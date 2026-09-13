<?php
/**
 * کار با جدول‌های گفتگو و پیام.
 */

defined( 'ABSPATH' ) || exit;

class Chat_Support_DB {

	/** نسخه‌ی ساختار جدول‌ها. */
	const DB_VERSION = '1.0.0';

	/**
	 * نام جدول گفتگوها.
	 *
	 * @return string
	 */
	public static function conversations_table() {
		global $wpdb;

		return $wpdb->prefix . 'chat_support_conversations';
	}

	/**
	 * نام جدول پیام‌ها.
	 *
	 * @return string
	 */
	public static function messages_table() {
		global $wpdb;

		return $wpdb->prefix . 'chat_support_messages';
	}

	/**
	 * ساخت یا به‌روزرسانی جدول‌ها.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$convos   = self::conversations_table();
		$messages = self::messages_table();

		$sql = "CREATE TABLE {$convos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			email varchar(100) NOT NULL DEFAULT '',
			page_url varchar(255) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'open',
			unread_admin int(11) NOT NULL DEFAULT 0,
			unread_visitor int(11) NOT NULL DEFAULT 0,
			last_message text NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status_updated (status,updated_at),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql .= "CREATE TABLE {$messages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			sender varchar(10) NOT NULL DEFAULT 'visitor',
			author_name varchar(100) NOT NULL DEFAULT '',
			message text NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id,id)
		) {$charset};";

		dbDelta( $sql );

		update_option( 'chat_support_db_version', self::DB_VERSION );
	}

	/**
	 * اگر ساختار جدول‌ها قدیمی بود، به‌روزرسانی کن.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'chat_support_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * ساخت گفتگوی جدید.
	 *
	 * @param array $data اطلاعات بازدیدکننده.
	 * @return object|null
	 */
	public static function create_conversation( $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array(
			'token'          => chat_support_generate_token(),
			'name'           => isset( $data['name'] ) ? $data['name'] : '',
			'email'          => isset( $data['email'] ) ? $data['email'] : '',
			'page_url'       => isset( $data['page_url'] ) ? $data['page_url'] : '',
			'user_agent'     => isset( $data['user_agent'] ) ? $data['user_agent'] : '',
			'ip'             => chat_support_get_ip(),
			'user_id'        => get_current_user_id(),
			'status'         => 'open',
			'unread_admin'   => 0,
			'unread_visitor' => 0,
			'last_message'   => '',
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$inserted = $wpdb->insert( self::conversations_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $inserted ) {
			return null;
		}

		return self::get_conversation( (int) $wpdb->insert_id );
	}

	/**
	 * دریافت گفتگو با شناسه.
	 *
	 * @param int $id شناسه گفتگو.
	 * @return object|null
	 */
	public static function get_conversation( $id ) {
		global $wpdb;

		$table = self::conversations_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * دریافت گفتگو با توکن بازدیدکننده.
	 *
	 * @param string $token توکن.
	 * @return object|null
	 */
	public static function get_conversation_by_token( $token ) {
		global $wpdb;

		if ( ! $token ) {
			return null;
		}

		$table = self::conversations_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) );
	}

	/**
	 * افزودن پیام به گفتگو.
	 *
	 * @param int    $conversation_id شناسه گفتگو.
	 * @param string $sender          visitor یا admin.
	 * @param string $message         متن پیام.
	 * @param string $author_name     نام نویسنده.
	 * @return object|null
	 */
	public static function add_message( $conversation_id, $sender, $message, $author_name = '' ) {
		global $wpdb;

		$now    = current_time( 'mysql' );
		$sender = ( 'admin' === $sender ) ? 'admin' : 'visitor';

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::messages_table(),
			array(
				'conversation_id' => $conversation_id,
				'sender'          => $sender,
				'author_name'     => $author_name,
				'message'         => $message,
				'created_at'      => $now,
			)
		);

		if ( ! $inserted ) {
			return null;
		}

		$message_id = (int) $wpdb->insert_id;
		$table      = self::conversations_table();
		$counter    = ( 'admin' === $sender ) ? 'unread_visitor' : 'unread_admin';

		// پیام تازه‌ی بازدیدکننده گفتگوی بسته‌شده را دوباره باز می‌کند؛ پاسخ اپراتور نه.
		$reopen = ( 'visitor' === $sender ) ? ", status = 'open'" : '';

		// شمارنده‌ی خوانده‌نشده را در همان کوئری زیاد می‌کنیم تا رقابت همزمانی پیش نیاید.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table}
				 SET {$counter} = {$counter} + 1, last_message = %s, updated_at = %s{$reopen}
				 WHERE id = %d",
				$message,
				$now,
				$conversation_id
			)
		);

		return self::get_message( $message_id );
	}

	/**
	 * دریافت یک پیام.
	 *
	 * @param int $id شناسه پیام.
	 * @return object|null
	 */
	public static function get_message( $id ) {
		global $wpdb;

		$table = self::messages_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * پیام‌های یک گفتگو، اختیاری فقط پیام‌های بعد از یک شناسه.
	 *
	 * @param int $conversation_id شناسه گفتگو.
	 * @param int $after           فقط پیام‌های با شناسه بزرگ‌تر از این.
	 * @param int $limit           حداکثر تعداد.
	 * @return array
	 */
	public static function get_messages( $conversation_id, $after = 0, $limit = 200 ) {
		global $wpdb;

		$table = self::messages_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE conversation_id = %d AND id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$conversation_id,
				$after,
				$limit
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * فهرست گفتگوها برای پنل مدیریت.
	 *
	 * @param array $args status، search، page، per_page.
	 * @return array
	 */
	public static function list_conversations( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => 'all',
				'search'   => '',
				'page'     => 1,
				'per_page' => 30,
			)
		);

		$table    = self::conversations_table();
		$where    = array( '1=1' );
		$params   = array();
		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		if ( in_array( $args['status'], array( 'open', 'closed' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s OR last_message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params )
				: "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
		);

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$query_params
			)
		);

		return array(
			'items'    => $rows ? $rows : array(),
			'total'    => $total,
			'page'     => max( 1, (int) $args['page'] ),
			'per_page' => $per_page,
		);
	}

	/**
	 * صفر کردن شمارنده‌ی خوانده‌نشده.
	 *
	 * @param int    $conversation_id شناسه گفتگو.
	 * @param string $who             admin یا visitor.
	 */
	public static function mark_read( $conversation_id, $who ) {
		global $wpdb;

		$column = ( 'admin' === $who ) ? 'unread_admin' : 'unread_visitor';

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::conversations_table(),
			array( $column => 0 ),
			array( 'id' => $conversation_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * تغییر وضعیت گفتگو.
	 *
	 * @param int    $conversation_id شناسه گفتگو.
	 * @param string $status          open یا closed.
	 */
	public static function set_status( $conversation_id, $status ) {
		global $wpdb;

		$status = ( 'closed' === $status ) ? 'closed' : 'open';

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::conversations_table(),
			array( 'status' => $status ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * به‌روزرسانی مشخصات بازدیدکننده.
	 *
	 * @param int   $conversation_id شناسه گفتگو.
	 * @param array $fields          ستون‌هایی که باید به‌روز شوند.
	 */
	public static function update_conversation( $conversation_id, $fields ) {
		global $wpdb;

		$allowed = array_intersect_key(
			$fields,
			array_flip( array( 'name', 'email', 'page_url', 'user_agent' ) )
		);

		if ( ! $allowed ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::conversations_table(),
			$allowed,
			array( 'id' => $conversation_id ),
			array_fill( 0, count( $allowed ), '%s' ),
			array( '%d' )
		);
	}

	/**
	 * حذف کامل یک گفتگو و پیام‌هایش.
	 *
	 * @param int $conversation_id شناسه گفتگو.
	 */
	public static function delete_conversation( $conversation_id ) {
		global $wpdb;

		$wpdb->delete( self::messages_table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::conversations_table(), array( 'id' => $conversation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * تعداد کل پیام‌های خوانده‌نشده برای اپراتور.
	 *
	 * @return int
	 */
	public static function unread_count() {
		global $wpdb;

		$table = self::conversations_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE unread_admin > 0" );
	}

	/**
	 * حذف گفتگوهای قدیمی‌تر از تعداد روز مشخص.
	 *
	 * @param int $days تعداد روز.
	 */
	public static function delete_older_than( $days ) {
		global $wpdb;

		$convos   = self::conversations_table();
		$messages = self::messages_table();
		// updated_at با زمان محلی سایت ذخیره شده، پس مرز حذف را هم محلی حساب می‌کنیم.
		$before = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$convos} WHERE updated_at < %s LIMIT 500", $before ) );

		if ( ! $ids ) {
			return;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$messages} WHERE conversation_id IN ({$placeholders})", $ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$convos} WHERE id IN ({$placeholders})", $ids ) );
	}
}
