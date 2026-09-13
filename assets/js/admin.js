/**
 * پنل گفتگوهای اپراتور.
 *
 * فهرست گفتگوها و پیام‌های گفتگوی باز با پولینگ به‌روز می‌شوند.
 */
( function () {
	'use strict';

	var cfg = window.ChatSupportAdmin;

	if ( ! cfg || ! document.getElementById( 'cs-inbox' ) ) {
		return;
	}

	var LIST_POLL = 10000;
	var THREAD_POLL = 4000;

	var state = {
		current: null,      // گفتگوی باز
		lastId: 0,          // آخرین پیام نمایش داده‌شده
		status: 'open',
		search: '',
		unread: -1,
		sending: false,
		listTimer: null,
		threadTimer: null,
		searchTimer: null
	};

	var el = {
		inbox: document.getElementById( 'cs-inbox' ),
		list: document.getElementById( 'cs-list' ),
		search: document.getElementById( 'cs-search' ),
		filter: document.getElementById( 'cs-filter' ),
		thread: document.getElementById( 'cs-thread' ),
		threadEmpty: document.getElementById( 'cs-thread-empty' ),
		name: document.getElementById( 'cs-thread-name' ),
		meta: document.getElementById( 'cs-thread-meta' ),
		messages: document.getElementById( 'cs-messages' ),
		replyForm: document.getElementById( 'cs-reply' ),
		replyText: document.getElementById( 'cs-reply-text' ),
		send: document.getElementById( 'cs-send' ),
		toggleStatus: document.getElementById( 'cs-toggle-status' ),
		remove: document.getElementById( 'cs-delete' ),
		back: document.getElementById( 'cs-back' ),
		live: document.getElementById( 'cs-live-dot' )
	};

	/* ---------------------------------------------------------------- */
	/* ابزار                                                            */
	/* ---------------------------------------------------------------- */

	function make( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	function linkify( target, text ) {
		var pattern = /(https?:\/\/[^\s<]+)/g;
		var index = 0;
		var match;

		while ( ( match = pattern.exec( text ) ) !== null ) {
			if ( match.index > index ) {
				target.appendChild( document.createTextNode( text.slice( index, match.index ) ) );
			}

			var link = make( 'a', null, match[ 0 ] );
			link.href = match[ 0 ];
			link.target = '_blank';
			link.rel = 'noopener nofollow';
			target.appendChild( link );

			index = match.index + match[ 0 ].length;
		}

		target.appendChild( document.createTextNode( text.slice( index ) ) );
	}

	function request( path, options ) {
		options = options || {};

		var init = {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		};

		if ( options.body ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.body );
		}

		return window.fetch( cfg.api + path, init ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'request_failed' );
			}

			return response.json();
		} );
	}

	function blink() {
		el.live.classList.add( 'cs-blink' );
		window.setTimeout( function () {
			el.live.classList.remove( 'cs-blink' );
		}, 300 );
	}

	/** بوق کوتاه برای پیام تازه — بدون فایل صوتی. */
	function beep() {
		if ( ! cfg.sound ) {
			return;
		}

		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;

			if ( ! Ctx ) {
				return;
			}

			var ctx = new Ctx();
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();

			osc.type = 'sine';
			osc.frequency.value = 880;
			gain.gain.setValueAtTime( 0.12, ctx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + 0.35 );

			osc.connect( gain );
			gain.connect( ctx.destination );
			osc.start();
			osc.stop( ctx.currentTime + 0.35 );

			osc.onended = function () {
				ctx.close();
			};
		} catch ( e ) {
			// اگر مرورگر اجازه نداد، بی‌خیال صدا می‌شویم.
		}
	}

	function notify( title, body ) {
		if ( ! ( 'Notification' in window ) || 'granted' !== window.Notification.permission || ! document.hidden ) {
			return;
		}

		try {
			new window.Notification( title, { body: body, tag: 'chat-support' } );
		} catch ( e ) {
			// روی بعضی مرورگرها بدون Service Worker خطا می‌دهد.
		}
	}

	/** به‌روزرسانی شمارنده‌ی کنار منوی وردپرس. */
	function updateMenuBadge( count ) {
		var item = document.querySelector( '#toplevel_page_chat-support .wp-menu-name .awaiting-mod' );
		var name = document.querySelector( '#toplevel_page_chat-support .wp-menu-name' );

		if ( count > 0 ) {
			if ( ! item && name ) {
				item = make( 'span', 'awaiting-mod' );
				name.appendChild( item );
			}

			if ( item ) {
				item.textContent = String( count );
			}
		} else if ( item ) {
			item.parentNode.removeChild( item );
		}

		document.title = count > 0
			? '(' + count + ') ' + document.title.replace( /^\(\d+\)\s*/, '' )
			: document.title.replace( /^\(\d+\)\s*/, '' );
	}

	/* ---------------------------------------------------------------- */
	/* فهرست گفتگوها                                                     */
	/* ---------------------------------------------------------------- */

	function renderList( items ) {
		el.list.textContent = '';

		if ( ! items.length ) {
			el.list.appendChild( make( 'li', 'cs-empty', 'گفتگویی نیست.' ) );
			return;
		}

		items.forEach( function ( item ) {
			var li = make( 'li' );
			li.dataset.id = item.id;

			if ( state.current && state.current.id === item.id ) {
				li.className = 'cs-active';
			}

			var top = make( 'div', 'cs-item-top' );
			top.appendChild( make( 'span', 'cs-item-name', item.name ) );

			if ( item.unread > 0 ) {
				top.appendChild( make( 'span', 'cs-dot', String( item.unread ) ) );
			}

			if ( 'closed' === item.status ) {
				top.appendChild( make( 'span', 'cs-tag-closed', 'بسته' ) );
			}

			top.appendChild( make( 'span', 'cs-item-time', item.ago ) );

			li.appendChild( top );
			li.appendChild( make( 'div', 'cs-item-preview', item.last_message || '—' ) );

			li.addEventListener( 'click', function () {
				openConversation( item );
			} );

			el.list.appendChild( li );
		} );
	}

	function loadList() {
		var query = '/conversations?status=' + encodeURIComponent( state.status ) +
			'&search=' + encodeURIComponent( state.search ) +
			'&per_page=50';

		return request( query ).then( function ( response ) {
			blink();
			renderList( response.items );

			if ( state.unread >= 0 && response.unread > state.unread ) {
				beep();
				notify( 'پیام جدید پشتیبانی', 'یک پیام تازه در پنل گفتگوها دارید.' );
			}

			if ( response.unread !== state.unread ) {
				updateMenuBadge( response.unread );
			}

			state.unread = response.unread;
		} ).catch( function () {
			// دور بعدی دوباره تلاش می‌شود.
		} );
	}

	/* ---------------------------------------------------------------- */
	/* گفتگوی باز                                                        */
	/* ---------------------------------------------------------------- */

	function appendMessage( message ) {
		if ( message.id <= state.lastId ) {
			return false;
		}

		state.lastId = message.id;

		var wrap = make( 'div', 'cs-m cs-m-' + ( 'admin' === message.sender ? 'admin' : 'visitor' ) );
		var bubble = make( 'div', 'cs-m-bubble' );

		linkify( bubble, message.message );

		wrap.appendChild( bubble );
		wrap.appendChild( make( 'div', 'cs-m-meta', ( message.author || '' ) + ' · ' + message.date + ' ' + message.time ) );

		el.messages.appendChild( wrap );

		return true;
	}

	function renderMessages( messages ) {
		var added = false;

		messages.forEach( function ( message ) {
			if ( appendMessage( message ) ) {
				added = true;
			}
		} );

		if ( added ) {
			el.messages.scrollTop = el.messages.scrollHeight;
		}

		return added;
	}

	function setHeader( conversation ) {
		var parts = [];

		if ( conversation.email ) {
			parts.push( conversation.email );
		}

		if ( conversation.ip ) {
			parts.push( conversation.ip );
		}

		parts.push( conversation.updated_at );

		el.name.textContent = conversation.name;
		el.meta.textContent = parts.join( ' · ' );
		el.meta.title = conversation.page_url || '';
		el.toggleStatus.textContent = 'closed' === conversation.status ? 'بازکردن گفتگو' : 'بستن گفتگو';
	}

	function openConversation( conversation ) {
		state.current = conversation;
		state.lastId = 0;

		el.messages.textContent = '';
		el.threadEmpty.hidden = true;
		el.thread.hidden = false;
		el.inbox.classList.add( 'cs-mobile-thread' );

		setHeader( conversation );
		highlightActive( conversation.id );
		loadThread();

		window.clearInterval( state.threadTimer );
		state.threadTimer = window.setInterval( loadThread, THREAD_POLL );
	}

	function highlightActive( id ) {
		Array.prototype.forEach.call( el.list.children, function ( li ) {
			li.classList.toggle( 'cs-active', Number( li.dataset.id ) === Number( id ) );
		} );
	}

	function loadThread() {
		if ( ! state.current ) {
			return Promise.resolve();
		}

		var id = state.current.id;

		return request( '/conversations/' + id + '?after=' + state.lastId ).then( function ( response ) {
			if ( ! state.current || state.current.id !== id ) {
				return; // در این فاصله گفتگوی دیگری باز شده.
			}

			state.current = response.conversation;
			setHeader( response.conversation );

			if ( renderMessages( response.messages ) ) {
				loadList();
			}
		} ).catch( function () {
			// بی‌صدا؛ دور بعد دوباره.
		} );
	}

	function closeThread() {
		state.current = null;
		state.lastId = 0;

		window.clearInterval( state.threadTimer );

		el.thread.hidden = true;
		el.threadEmpty.hidden = false;
		el.inbox.classList.remove( 'cs-mobile-thread' );
		highlightActive( 0 );
	}

	function sendReply() {
		var text = el.replyText.value.trim();

		if ( ! text || ! state.current || state.sending ) {
			return;
		}

		state.sending = true;
		el.send.disabled = true;

		request( '/conversations/' + state.current.id + '/reply', {
			method: 'POST',
			body: { message: text }
		} ).then( function ( response ) {
			el.replyText.value = '';
			renderMessages( [ response.message ] );
			loadList();
		} ).catch( function () {
			window.alert( 'ارسال پاسخ انجام نشد. دوباره تلاش کنید.' );
		} ).then( function () {
			state.sending = false;
			el.send.disabled = false;
			el.replyText.focus();
		} );
	}

	/* ---------------------------------------------------------------- */
	/* رویدادها                                                          */
	/* ---------------------------------------------------------------- */

	el.replyForm.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		sendReply();
	} );

	el.replyText.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && ( event.ctrlKey || event.metaKey ) ) {
			event.preventDefault();
			sendReply();
		}
	} );

	el.toggleStatus.addEventListener( 'click', function () {
		if ( ! state.current ) {
			return;
		}

		var next = 'closed' === state.current.status ? 'open' : 'closed';

		request( '/conversations/' + state.current.id + '/status', {
			method: 'POST',
			body: { status: next }
		} ).then( function () {
			state.current.status = next;
			setHeader( state.current );
			loadList();
		} );
	} );

	el.remove.addEventListener( 'click', function () {
		if ( ! state.current || ! window.confirm( 'این گفتگو و همه‌ی پیام‌هایش حذف شود؟' ) ) {
			return;
		}

		request( '/conversations/' + state.current.id, { method: 'DELETE' } ).then( function () {
			closeThread();
			loadList();
		} );
	} );

	el.back.addEventListener( 'click', closeThread );

	el.filter.addEventListener( 'change', function () {
		state.status = el.filter.value;
		loadList();
	} );

	el.search.addEventListener( 'input', function () {
		window.clearTimeout( state.searchTimer );
		state.searchTimer = window.setTimeout( function () {
			state.search = el.search.value.trim();
			loadList();
		}, 400 );
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			loadList();
			loadThread();
		}
	} );

	/* ---------------------------------------------------------------- */
	/* شروع                                                             */
	/* ---------------------------------------------------------------- */

	if ( 'Notification' in window && 'default' === window.Notification.permission ) {
		// اجازه‌ی اعلان را بعد از اولین کلیک کاربر می‌گیریم (سیاست مرورگرها).
		document.addEventListener( 'click', function once() {
			document.removeEventListener( 'click', once );
			window.Notification.requestPermission();
		} );
	}

	loadList();
	state.listTimer = window.setInterval( loadList, LIST_POLL );
}() );
