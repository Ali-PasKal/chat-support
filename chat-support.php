<?php
/**
 * Plugin Name:       Chat Support
 * Plugin URI:        https://github.com/Ali-PasKal/chat-support
 * Description:       چت آنلاین و پشتیبانی ساده برای وردپرس. ویجت گفتگو روی سایت + پنل مدیریت گفتگوها که روی موبایل هم کار می‌کند. بدون سرویس خارجی، همه‌چیز روی خود سایت.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Ali PasKal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chat-support
 */

defined( 'ABSPATH' ) || exit;

define( 'CHAT_SUPPORT_VERSION', '1.0.0' );
define( 'CHAT_SUPPORT_FILE', __FILE__ );
define( 'CHAT_SUPPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHAT_SUPPORT_URL', plugin_dir_url( __FILE__ ) );

require_once CHAT_SUPPORT_PATH . 'includes/functions.php';
require_once CHAT_SUPPORT_PATH . 'includes/class-chat-support-db.php';
require_once CHAT_SUPPORT_PATH . 'includes/class-chat-support-rest.php';
require_once CHAT_SUPPORT_PATH . 'includes/class-chat-support-widget.php';
require_once CHAT_SUPPORT_PATH . 'includes/class-chat-support-admin.php';

/**
 * راه‌اندازی افزونه.
 */
function chat_support_init() {
	Chat_Support_DB::maybe_upgrade();
	Chat_Support_REST::init();
	Chat_Support_Widget::init();

	if ( is_admin() ) {
		Chat_Support_Admin::init();
	}
}
add_action( 'plugins_loaded', 'chat_support_init' );

/**
 * فعال‌سازی: ساخت جدول‌ها و مقادیر پیش‌فرض.
 */
function chat_support_activate() {
	Chat_Support_DB::install();

	if ( false === get_option( 'chat_support_settings' ) ) {
		add_option( 'chat_support_settings', chat_support_default_settings() );
	}

	if ( ! wp_next_scheduled( 'chat_support_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'chat_support_daily_cleanup' );
	}
}
register_activation_hook( __FILE__, 'chat_support_activate' );

/**
 * غیرفعال‌سازی: فقط زمان‌بندی پاک می‌شود؛ داده‌ها دست‌نخورده می‌مانند.
 */
function chat_support_deactivate() {
	wp_clear_scheduled_hook( 'chat_support_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'chat_support_deactivate' );

/**
 * حذف گفتگوهای قدیمی بر اساس تنظیم «مدت نگهداری».
 */
function chat_support_daily_cleanup() {
	$days = (int) chat_support_get_setting( 'retention_days' );

	if ( $days > 0 ) {
		Chat_Support_DB::delete_older_than( $days );
	}
}
add_action( 'chat_support_daily_cleanup', 'chat_support_daily_cleanup' );
