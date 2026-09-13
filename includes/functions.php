<?php
/**
 * توابع کمکی و تنظیمات افزونه.
 */

defined( 'ABSPATH' ) || exit;

/**
 * تنظیمات پیش‌فرض افزونه.
 *
 * @return array
 */
function chat_support_default_settings() {
	return array(
		'enabled'        => 1,
		'title'          => 'پشتیبانی آنلاین',
		'subtitle'       => 'معمولاً در چند دقیقه پاسخ می‌دهیم',
		'welcome'        => 'سلام 👋 چطور می‌توانیم کمکتان کنیم؟',
		'agent_name'     => 'پشتیبانی',
		'color'          => '#2563eb',
		'position'       => 'right',
		'require_name'   => 1,
		'require_email'  => 0,
		'sound'          => 1,
		'retention_days' => 0,
	);
}

/**
 * همه‌ی تنظیمات همراه با مقادیر پیش‌فرض.
 *
 * @return array
 */
function chat_support_get_settings() {
	$saved = get_option( 'chat_support_settings', array() );

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return wp_parse_args( $saved, chat_support_default_settings() );
}

/**
 * یک تنظیم مشخص.
 *
 * @param string $key کلید تنظیم.
 * @return mixed
 */
function chat_support_get_setting( $key ) {
	$settings = chat_support_get_settings();

	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * پاک‌سازی تنظیمات پیش از ذخیره.
 *
 * @param array $input ورودی فرم.
 * @return array
 */
function chat_support_sanitize_settings( $input ) {
	$defaults = chat_support_default_settings();
	$clean    = array();

	$clean['enabled']       = empty( $input['enabled'] ) ? 0 : 1;
	$clean['require_name']  = empty( $input['require_name'] ) ? 0 : 1;
	$clean['require_email'] = empty( $input['require_email'] ) ? 0 : 1;
	$clean['sound']         = empty( $input['sound'] ) ? 0 : 1;

	foreach ( array( 'title', 'subtitle', 'agent_name' ) as $key ) {
		$value         = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
		$clean[ $key ] = '' === $value ? $defaults[ $key ] : $value;
	}

	$welcome           = isset( $input['welcome'] ) ? sanitize_textarea_field( $input['welcome'] ) : '';
	$clean['welcome']  = '' === $welcome ? $defaults['welcome'] : $welcome;

	$color             = isset( $input['color'] ) ? sanitize_hex_color( $input['color'] ) : '';
	$clean['color']    = $color ? $color : $defaults['color'];

	$clean['position'] = ( isset( $input['position'] ) && 'left' === $input['position'] ) ? 'left' : 'right';

	// absint( '-5' ) برابر 5 می‌شود، پس عمداً از max استفاده می‌کنیم تا عدد منفی صفر شود.
	$clean['retention_days'] = isset( $input['retention_days'] ) ? max( 0, (int) $input['retention_days'] ) : 0;

	return $clean;
}

/**
 * دسترسی لازم برای دیدن و پاسخ دادن به گفتگوها.
 *
 * با فیلتر chat_support_capability می‌توانید به نقش‌های دیگر هم دسترسی بدهید.
 *
 * @return string
 */
function chat_support_capability() {
	return apply_filters( 'chat_support_capability', 'manage_options' );
}

/**
 * آیا کاربر فعلی اپراتور پشتیبانی است؟
 *
 * @return bool
 */
function chat_support_current_user_is_agent() {
	return current_user_can( chat_support_capability() );
}

/**
 * آی‌پی بازدیدکننده (فقط برای نمایش در پنل).
 *
 * @return string
 */
function chat_support_get_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	$ip = filter_var( $ip, FILTER_VALIDATE_IP );

	return $ip ? $ip : '';
}

/**
 * ساخت توکن تصادفی برای شناسایی بازدیدکننده.
 *
 * @return string
 */
function chat_support_generate_token() {
	return wp_generate_password( 32, false, false );
}

/**
 * بیشترین طول مجاز هر پیام.
 *
 * @return int
 */
function chat_support_max_message_length() {
	return (int) apply_filters( 'chat_support_max_message_length', 2000 );
}
