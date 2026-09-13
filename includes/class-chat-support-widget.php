<?php
/**
 * نمایش ویجت چت در سایت.
 */

defined( 'ABSPATH' ) || exit;

class Chat_Support_Widget {

	/**
	 * ثبت هوک‌ها.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * آیا ویجت باید نمایش داده شود؟
	 *
	 * @return bool
	 */
	public static function should_display() {
		if ( ! chat_support_get_setting( 'enabled' ) ) {
			return false;
		}

		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}

		/**
		 * برای پنهان کردن ویجت در بعضی صفحه‌ها:
		 * add_filter( 'chat_support_show_widget', fn( $show ) => is_page( 'checkout' ) ? false : $show );
		 *
		 * @param bool $show نمایش داده شود یا نه.
		 */
		return (bool) apply_filters( 'chat_support_show_widget', true );
	}

	/**
	 * بارگذاری CSS و JS ویجت.
	 */
	public static function enqueue() {
		if ( ! self::should_display() ) {
			return;
		}

		wp_enqueue_style(
			'chat-support-widget',
			CHAT_SUPPORT_URL . 'assets/css/widget.css',
			array(),
			CHAT_SUPPORT_VERSION
		);

		wp_enqueue_script(
			'chat-support-widget',
			CHAT_SUPPORT_URL . 'assets/js/widget.js',
			array(),
			CHAT_SUPPORT_VERSION,
			true
		);

		$settings = chat_support_get_settings();

		wp_localize_script(
			'chat-support-widget',
			'ChatSupportData',
			array(
				'api'          => esc_url_raw( rest_url( Chat_Support_REST::NS ) ),
				'title'        => $settings['title'],
				'subtitle'     => $settings['subtitle'],
				'welcome'      => $settings['welcome'],
				'agentName'    => $settings['agent_name'],
				'color'        => $settings['color'],
				'position'     => $settings['position'],
				'requireName'  => (bool) $settings['require_name'],
				'requireEmail' => (bool) $settings['require_email'],
				'rtl'          => is_rtl(),
				'maxLength'    => chat_support_max_message_length(),
				// برای کاربران مهمان نانس نمی‌فرستیم تا با کش کامل صفحه مشکل پیش نیاید.
				'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'i18n'         => array(
					'open'        => 'گفتگو با پشتیبانی',
					'close'       => 'بستن',
					'placeholder' => 'پیامتان را بنویسید…',
					'send'        => 'ارسال',
					'name'        => 'نام شما',
					'email'       => 'ایمیل شما',
					'start'       => 'شروع گفتگو',
					'intro'       => 'برای شروع گفتگو، اطلاعات زیر را کامل کنید:',
					'error'       => 'ارسال نشد. اتصال اینترنت را بررسی کنید.',
					'you'         => 'شما',
					'nameError'   => 'لطفاً نامتان را وارد کنید.',
					'emailError'  => 'لطفاً ایمیل معتبر وارد کنید.',
				),
			)
		);
	}

	/**
	 * خروجی ریشه‌ی ویجت در فوتر.
	 */
	public static function render() {
		if ( ! self::should_display() ) {
			return;
		}

		echo '<div id="chat-support-root"></div>';
	}
}
