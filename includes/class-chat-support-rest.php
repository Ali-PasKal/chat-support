<?php
/**
 * مسیرهای REST برای ویجت بازدیدکننده و پنل اپراتور.
 *
 * فضای نام: /wp-json/chat-support/v1/
 */

defined( 'ABSPATH' ) || exit;

class Chat_Support_REST {

	const NS = 'chat-support/v1';

	/**
	 * ثبت هوک‌ها.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * ثبت مسیرها.
	 */
	public static function register_routes() {
		// --- بازدیدکننده (عمومی) ---
		register_rest_route(
			self::NS,
			'/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'visitor_session' ),
				'permission_callback' => array( __CLASS__, 'widget_enabled' ),
			)
		);

		register_rest_route(
			self::NS,
			'/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'visitor_messages' ),
				'permission_callback' => array( __CLASS__, 'widget_enabled' ),
			)
		);

		register_rest_route(
			self::NS,
			'/message',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'visitor_send' ),
				'permission_callback' => array( __CLASS__, 'widget_enabled' ),
			)
		);

		// --- اپراتور (نیازمند دسترسی) ---
		register_rest_route(
			self::NS,
			'/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'admin_conversations' ),
				'permission_callback' => array( __CLASS__, 'agent_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/conversations/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'admin_conversation' ),
					'permission_callback' => array( __CLASS__, 'agent_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'admin_delete' ),
					'permission_callback' => array( __CLASS__, 'agent_permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/conversations/(?P<id>\d+)/reply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_reply' ),
				'permission_callback' => array( __CLASS__, 'agent_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/conversations/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_status' ),
				'permission_callback' => array( __CLASS__, 'agent_permission' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * دسترسی‌ها
	 * ------------------------------------------------------------------- */

	/**
	 * مسیرهای بازدیدکننده فقط وقتی چت روشن است کار می‌کنند.
	 *
	 * @return bool|WP_Error
	 */
	public static function widget_enabled() {
		if ( ! chat_support_get_setting( 'enabled' ) ) {
			return new WP_Error( 'chat_support_disabled', 'چت آنلاین غیرفعال است.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * دسترسی اپراتور.
	 *
	 * @return bool|WP_Error
	 */
	public static function agent_permission() {
		if ( ! chat_support_current_user_is_agent() ) {
			return new WP_Error( 'chat_support_forbidden', 'دسترسی ندارید.', array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * ابزار
	 * ------------------------------------------------------------------- */

	/**
	 * تبدیل رکورد پیام به آرایه‌ی خروجی.
	 *
	 * @param object $row رکورد پیام.
	 * @return array
	 */
	private static function format_message( $row ) {
		return array(
			'id'      => (int) $row->id,
			'sender'  => $row->sender,
			'author'  => $row->author_name,
			'message' => $row->message,
			'time'    => mysql2date( 'H:i', $row->created_at ),
			'date'    => mysql2date( get_option( 'date_format' ), $row->created_at ),
		);
	}

	/**
	 * تبدیل فهرست پیام‌ها.
	 *
	 * @param array $rows رکوردها.
	 * @return array
	 */
	private static function format_messages( $rows ) {
		return array_map( array( __CLASS__, 'format_message' ), $rows );
	}

	/**
	 * تبدیل رکورد گفتگو برای پنل.
	 *
	 * @param object $row رکورد گفتگو.
	 * @return array
	 */
	private static function format_conversation( $row ) {
		$name = $row->name ? $row->name : 'مهمان #' . (int) $row->id;

		// updated_at زمان محلی است؛ برای فاصله‌ی زمانی باید به UTC تبدیل شود.
		$timestamp = (int) get_gmt_from_date( $row->updated_at, 'U' );

		return array(
			'id'           => (int) $row->id,
			'name'         => $name,
			'email'        => $row->email,
			'status'       => $row->status,
			'unread'       => (int) $row->unread_admin,
			'last_message' => wp_trim_words( $row->last_message, 12, '…' ),
			'page_url'     => $row->page_url,
			'ip'           => $row->ip,
			'updated_at'   => mysql2date( 'Y/m/d H:i', $row->updated_at ),
			'ago'          => sprintf( '%s پیش', human_time_diff( $timestamp, time() ) ),
		);
	}

	/**
	 * جلوگیری از ارسال سیل‌آسای پیام از یک آی‌پی.
	 *
	 * @return bool true یعنی مجاز است.
	 */
	private static function check_flood( $fallback = '' ) {
		$ip = chat_support_get_ip();

		// اگر آی‌پی در دسترس نبود، سطل شمارش را روی توکن می‌بندیم تا محدودیت خاموش نشود.
		$bucket = $ip ? $ip : ( $fallback ? 'token:' . $fallback : 'anon' );

		$key   = 'chat_support_flood_' . md5( $bucket );
		$count = (int) get_transient( $key );
		$max   = (int) apply_filters( 'chat_support_messages_per_minute', 20 );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * پیدا کردن گفتگو از روی توکن درخواست.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return object|WP_Error
	 */
	private static function conversation_from_token( $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$convo = Chat_Support_DB::get_conversation_by_token( $token );

		if ( ! $convo ) {
			return new WP_Error( 'chat_support_no_session', 'گفتگو پیدا نشد.', array( 'status' => 404 ) );
		}

		return $convo;
	}

	/* ---------------------------------------------------------------------
	 * مسیرهای بازدیدکننده
	 * ------------------------------------------------------------------- */

	/**
	 * شروع گفتگوی جدید یا ادامه‌ی گفتگوی قبلی.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response
	 */
	public static function visitor_session( $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$page  = esc_url_raw( (string) $request->get_param( 'page_url' ) );
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$convo = $token ? Chat_Support_DB::get_conversation_by_token( $token ) : null;

		if ( ! $convo ) {
			if ( ! self::check_flood() ) {
				return new WP_Error( 'chat_support_flood', 'تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.', array( 'status' => 429 ) );
			}

			// اگر کاربر وارد سایت شده، نام و ایمیلش را خودمان برمی‌داریم.
			if ( is_user_logged_in() ) {
				$user  = wp_get_current_user();
				$name  = $name ? $name : $user->display_name;
				$email = $email ? $email : $user->user_email;
			}

			$convo = Chat_Support_DB::create_conversation(
				array(
					'name'       => mb_substr( $name, 0, 100 ),
					'email'      => $email,
					'page_url'   => mb_substr( $page, 0, 255 ),
					'user_agent' => mb_substr( $agent, 0, 255 ),
				)
			);

			if ( ! $convo ) {
				return new WP_Error( 'chat_support_error', 'ساخت گفتگو ممکن نشد.', array( 'status' => 500 ) );
			}
		} else {
			$update = array();

			if ( $name && $name !== $convo->name ) {
				$update['name'] = mb_substr( $name, 0, 100 );
			}

			if ( $email && $email !== $convo->email ) {
				$update['email'] = $email;
			}

			if ( $page && $page !== $convo->page_url ) {
				$update['page_url'] = mb_substr( $page, 0, 255 );
			}

			if ( $update ) {
				Chat_Support_DB::update_conversation( (int) $convo->id, $update );

				// تا پاسخ، مقدار تازه را برگرداند نه مقدار قبلی را.
				foreach ( $update as $field => $value ) {
					$convo->$field = $value;
				}
			}
		}

		$messages = Chat_Support_DB::get_messages( (int) $convo->id );
		Chat_Support_DB::mark_read( (int) $convo->id, 'visitor' );

		return rest_ensure_response(
			array(
				'token'    => $convo->token,
				'name'     => $convo->name,
				'email'    => $convo->email,
				'messages' => self::format_messages( $messages ),
			)
		);
	}

	/**
	 * گرفتن پیام‌های تازه (پولینگ ویجت).
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function visitor_messages( $request ) {
		$convo = self::conversation_from_token( $request );

		if ( is_wp_error( $convo ) ) {
			return $convo;
		}

		$after    = absint( $request->get_param( 'after' ) );
		$messages = Chat_Support_DB::get_messages( (int) $convo->id, $after );
		$unread   = (int) $convo->unread_visitor;

		// وقتی پنجره‌ی چت باز است، پیام‌ها خوانده‌شده حساب می‌شوند.
		if ( $request->get_param( 'open' ) && $unread > 0 ) {
			Chat_Support_DB::mark_read( (int) $convo->id, 'visitor' );
		}

		return rest_ensure_response(
			array(
				'messages' => self::format_messages( $messages ),
				'unread'   => $unread,
			)
		);
	}

	/**
	 * ارسال پیام از طرف بازدیدکننده.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function visitor_send( $request ) {
		$convo = self::conversation_from_token( $request );

		if ( is_wp_error( $convo ) ) {
			return $convo;
		}

		if ( ! self::check_flood( $convo->token ) ) {
			return new WP_Error( 'chat_support_flood', 'تعداد پیام‌ها زیاد است. کمی بعد دوباره تلاش کنید.', array( 'status' => 429 ) );
		}

		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$message = mb_substr( trim( $message ), 0, chat_support_max_message_length() );

		if ( '' === $message ) {
			return new WP_Error( 'chat_support_empty', 'پیام خالی است.', array( 'status' => 400 ) );
		}

		$row = Chat_Support_DB::add_message( (int) $convo->id, 'visitor', $message, $convo->name );

		if ( ! $row ) {
			return new WP_Error( 'chat_support_error', 'ارسال پیام ممکن نشد.', array( 'status' => 500 ) );
		}

		/**
		 * بعد از ثبت پیام بازدیدکننده اجرا می‌شود (مثلاً برای ارسال ایمیل یا تلگرام).
		 *
		 * @param object $row   رکورد پیام.
		 * @param object $convo رکورد گفتگو.
		 */
		do_action( 'chat_support_visitor_message', $row, $convo );

		return rest_ensure_response( array( 'message' => self::format_message( $row ) ) );
	}

	/* ---------------------------------------------------------------------
	 * مسیرهای اپراتور
	 * ------------------------------------------------------------------- */

	/**
	 * فهرست گفتگوها + تعداد خوانده‌نشده‌ها.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response
	 */
	public static function admin_conversations( $request ) {
		$result = Chat_Support_DB::list_conversations(
			array(
				'status'   => sanitize_text_field( (string) $request->get_param( 'status' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => absint( $request->get_param( 'page' ) ),
				'per_page' => absint( $request->get_param( 'per_page' ) ),
			)
		);

		return rest_ensure_response(
			array(
				'items'    => array_map( array( __CLASS__, 'format_conversation' ), $result['items'] ),
				'total'    => $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
				'unread'   => Chat_Support_DB::unread_count(),
			)
		);
	}

	/**
	 * یک گفتگو با پیام‌هایش.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_conversation( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$convo = Chat_Support_DB::get_conversation( $id );

		if ( ! $convo ) {
			return new WP_Error( 'chat_support_not_found', 'گفتگو پیدا نشد.', array( 'status' => 404 ) );
		}

		$after    = absint( $request->get_param( 'after' ) );
		$messages = Chat_Support_DB::get_messages( $id, $after );

		if ( (int) $convo->unread_admin > 0 ) {
			Chat_Support_DB::mark_read( $id, 'admin' );
			$convo->unread_admin = 0;
		}

		return rest_ensure_response(
			array(
				'conversation' => self::format_conversation( $convo ),
				'messages'     => self::format_messages( $messages ),
				'unread'       => Chat_Support_DB::unread_count(),
			)
		);
	}

	/**
	 * پاسخ اپراتور.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_reply( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$convo = Chat_Support_DB::get_conversation( $id );

		if ( ! $convo ) {
			return new WP_Error( 'chat_support_not_found', 'گفتگو پیدا نشد.', array( 'status' => 404 ) );
		}

		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$message = mb_substr( trim( $message ), 0, chat_support_max_message_length() );

		if ( '' === $message ) {
			return new WP_Error( 'chat_support_empty', 'پیام خالی است.', array( 'status' => 400 ) );
		}

		$author = chat_support_get_setting( 'agent_name' );
		$row    = Chat_Support_DB::add_message( $id, 'admin', $message, $author );

		if ( ! $row ) {
			return new WP_Error( 'chat_support_error', 'ارسال پاسخ ممکن نشد.', array( 'status' => 500 ) );
		}

		Chat_Support_DB::mark_read( $id, 'admin' );

		/**
		 * بعد از ثبت پاسخ اپراتور اجرا می‌شود.
		 *
		 * @param object $row   رکورد پیام.
		 * @param object $convo رکورد گفتگو.
		 */
		do_action( 'chat_support_admin_message', $row, $convo );

		return rest_ensure_response( array( 'message' => self::format_message( $row ) ) );
	}

	/**
	 * بستن یا بازکردن گفتگو.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response
	 */
	public static function admin_status( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$status = ( 'closed' === $request->get_param( 'status' ) ) ? 'closed' : 'open';

		Chat_Support_DB::set_status( $id, $status );

		return rest_ensure_response( array( 'status' => $status ) );
	}

	/**
	 * حذف گفتگو.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response
	 */
	public static function admin_delete( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		Chat_Support_DB::delete_conversation( $id );

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
