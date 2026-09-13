<?php
/**
 * هنگام حذف افزونه از وردپرس اجرا می‌شود.
 *
 * تنظیمات و زمان‌بندی پاک می‌شوند، اما جدول گفتگوها دست‌نخورده می‌ماند تا
 * با یک حذف اشتباهی، تاریخچه‌ی پشتیبانی از بین نرود.
 * روش پاک کردن کامل در README توضیح داده شده است.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'chat_support_settings' );
delete_option( 'chat_support_db_version' );

wp_clear_scheduled_hook( 'chat_support_daily_cleanup' );
