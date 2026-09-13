<?php
/**
 * پنل مدیریت: صندوق گفتگوها و صفحه تنظیمات.
 */

defined( 'ABSPATH' ) || exit;

class Chat_Support_Admin {

	const MENU_SLUG     = 'chat-support';
	const SETTINGS_SLUG = 'chat-support-settings';

	/**
	 * ثبت هوک‌ها.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CHAT_SUPPORT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * افزودن منوها.
	 */
	public static function add_menu() {
		$cap    = chat_support_capability();
		$unread = Chat_Support_DB::unread_count();
		$title  = 'چت پشتیبانی';

		if ( $unread > 0 ) {
			$title .= ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $unread ) ) . '</span>';
		}

		add_menu_page(
			'چت پشتیبانی',
			$title,
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_inbox' ),
			'dashicons-format-chat',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			'گفتگوها',
			'گفتگوها',
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_inbox' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'تنظیمات چت',
			'تنظیمات',
			$cap,
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * لینک «تنظیمات» در فهرست افزونه‌ها.
	 *
	 * @param array $links لینک‌های فعلی.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">تنظیمات</a>' );

		return $links;
	}

	/**
	 * ثبت تنظیمات.
	 */
	public static function register_settings() {
		register_setting(
			'chat_support_settings_group',
			'chat_support_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'chat_support_sanitize_settings',
				'default'           => chat_support_default_settings(),
			)
		);
	}

	/**
	 * بارگذاری فایل‌های پنل.
	 *
	 * @param string $hook شناسه صفحه.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'chat-support-admin',
			CHAT_SUPPORT_URL . 'assets/css/admin.css',
			array(),
			CHAT_SUPPORT_VERSION
		);

		// فقط در صفحه‌ی گفتگوها به اسکریپت نیاز داریم.
		if ( false !== strpos( $hook, self::SETTINGS_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'chat-support-admin',
			CHAT_SUPPORT_URL . 'assets/js/admin.js',
			array(),
			CHAT_SUPPORT_VERSION,
			true
		);

		wp_localize_script(
			'chat-support-admin',
			'ChatSupportAdmin',
			array(
				'api'       => esc_url_raw( rest_url( Chat_Support_REST::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'sound'     => (bool) chat_support_get_setting( 'sound' ),
				'agentName' => chat_support_get_setting( 'agent_name' ),
				'maxLength' => chat_support_max_message_length(),
			)
		);
	}

	/**
	 * صفحه‌ی صندوق گفتگوها.
	 */
	public static function render_inbox() {
		if ( ! chat_support_current_user_is_agent() ) {
			wp_die( 'دسترسی ندارید.' );
		}
		?>
		<div class="wrap cs-wrap">
			<h1 class="cs-page-title">
				گفتگوها
				<span class="cs-live" id="cs-live-dot" title="به‌روزرسانی خودکار"></span>
			</h1>

			<div class="cs-inbox" id="cs-inbox">
				<div class="cs-list-pane">
					<div class="cs-list-tools">
						<input type="search" id="cs-search" class="cs-search" placeholder="جستجو در گفتگوها…">
						<select id="cs-filter" class="cs-filter">
							<option value="open">باز</option>
							<option value="all">همه</option>
							<option value="closed">بسته‌شده</option>
						</select>
					</div>
					<ul class="cs-list" id="cs-list">
						<li class="cs-empty">در حال بارگذاری…</li>
					</ul>
				</div>

				<div class="cs-thread-pane" id="cs-thread-pane">
					<div class="cs-thread-empty" id="cs-thread-empty">
						یک گفتگو را از فهرست انتخاب کنید.
					</div>

					<div class="cs-thread" id="cs-thread" hidden>
						<div class="cs-thread-head">
							<button type="button" class="cs-back" id="cs-back">بازگشت</button>
							<div class="cs-thread-who">
								<strong id="cs-thread-name"></strong>
								<span id="cs-thread-meta"></span>
							</div>
							<div class="cs-thread-actions">
								<button type="button" class="button" id="cs-toggle-status">بستن گفتگو</button>
								<button type="button" class="button cs-danger" id="cs-delete">حذف</button>
							</div>
						</div>

						<div class="cs-messages" id="cs-messages"></div>

						<form class="cs-reply" id="cs-reply">
							<textarea id="cs-reply-text" rows="2" placeholder="پاسخ خود را بنویسید… (Ctrl+Enter برای ارسال)"></textarea>
							<button type="submit" class="button button-primary" id="cs-send">ارسال</button>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * صفحه‌ی تنظیمات.
	 */
	public static function render_settings() {
		if ( ! chat_support_current_user_is_agent() ) {
			wp_die( 'دسترسی ندارید.' );
		}

		$s = chat_support_get_settings();
		?>
		<div class="wrap cs-wrap">
			<h1>تنظیمات چت پشتیبانی</h1>

			<form method="post" action="options.php" class="cs-settings">
				<?php settings_fields( 'chat_support_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">وضعیت</th>
						<td>
							<label>
								<input type="checkbox" name="chat_support_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>>
								نمایش ویجت چت در سایت
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cs-title">عنوان</label></th>
						<td>
							<input type="text" id="cs-title" class="regular-text" name="chat_support_settings[title]" value="<?php echo esc_attr( $s['title'] ); ?>">
							<p class="description">بالای پنجره‌ی چت نمایش داده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cs-subtitle">زیرعنوان</label></th>
						<td><input type="text" id="cs-subtitle" class="regular-text" name="chat_support_settings[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cs-welcome">پیام خوش‌آمد</label></th>
						<td>
							<textarea id="cs-welcome" class="large-text" rows="3" name="chat_support_settings[welcome]"><?php echo esc_textarea( $s['welcome'] ); ?></textarea>
							<p class="description">اولین چیزی که بازدیدکننده می‌بیند. در دیتابیس ذخیره نمی‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cs-agent">نام پشتیبان</label></th>
						<td>
							<input type="text" id="cs-agent" class="regular-text" name="chat_support_settings[agent_name]" value="<?php echo esc_attr( $s['agent_name'] ); ?>">
							<p class="description">نامی که کنار پاسخ‌های شما به بازدیدکننده نشان داده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cs-color">رنگ اصلی</label></th>
						<td><input type="color" id="cs-color" name="chat_support_settings[color]" value="<?php echo esc_attr( $s['color'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">جای دکمه</th>
						<td>
							<label><input type="radio" name="chat_support_settings[position]" value="right" <?php checked( $s['position'], 'right' ); ?>> پایین راست</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="chat_support_settings[position]" value="left" <?php checked( $s['position'], 'left' ); ?>> پایین چپ</label>
						</td>
					</tr>
					<tr>
						<th scope="row">فرم پیش از چت</th>
						<td>
							<label>
								<input type="checkbox" name="chat_support_settings[require_name]" value="1" <?php checked( $s['require_name'], 1 ); ?>>
								گرفتن نام
							</label><br>
							<label>
								<input type="checkbox" name="chat_support_settings[require_email]" value="1" <?php checked( $s['require_email'], 1 ); ?>>
								گرفتن ایمیل
							</label>
							<p class="description">اگر هر دو خاموش باشند، بازدیدکننده مستقیم وارد چت می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">صدای پیام جدید</th>
						<td>
							<label>
								<input type="checkbox" name="chat_support_settings[sound]" value="1" <?php checked( $s['sound'], 1 ); ?>>
								پخش صدا در پنل وقتی پیام تازه می‌رسد
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cs-retention">مدت نگهداری</label></th>
						<td>
							<input type="number" id="cs-retention" min="0" step="1" class="small-text" name="chat_support_settings[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>">
							روز
							<p class="description">گفتگوهای قدیمی‌تر از این تعداد روز خودکار حذف می‌شوند. صفر یعنی هیچ‌وقت حذف نشود.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<div class="cs-help-box">
				<h2>دسترسی با موبایل</h2>
				<p>
					صفحه‌ی «گفتگوها» برای موبایل طراحی شده است. کافی است با مرورگر گوشی وارد پیشخوان وردپرس شوید:
					<code><?php echo esc_html( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?></code>
				</p>
				<p>
					در کروم اندروید «افزودن به صفحه اصلی» و در سافاری آیفون «Add to Home Screen» را بزنید تا مثل یک اپلیکیشن باز شود.
				</p>
			</div>
		</div>
		<?php
	}
}
