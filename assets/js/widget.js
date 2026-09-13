/**
 * ویجت چت پشتیبانی.
 *
 * بدون وابستگی به کتابخانه. پیام‌ها با پولینگ ساده گرفته می‌شوند تا روی
 * هاست‌های اشتراکی هم بدون دردسر کار کند.
 */
( function () {
	'use strict';

	var data = window.ChatSupportData;

	if ( ! data || ! document.getElementById( 'chat-support-root' ) ) {
		return;
	}

	var STORAGE_KEY = 'chat_support_token';
	var POLL_OPEN = 4000;   // وقتی پنجره باز است
	var POLL_IDLE = 25000;  // وقتی بسته است، فقط برای شمارنده

	var state = {
		token: null,
		open: false,
		lastId: 0,
		unread: 0,
		started: false,
		sending: false,
		timer: null,
		animTimer: null
	};

	var el = {};

	/* ---------------------------------------------------------------- */
	/* ابزار                                                            */
	/* ---------------------------------------------------------------- */

	function store( key, value ) {
		try {
			if ( value === null ) {
				window.localStorage.removeItem( key );
			} else if ( value === undefined ) {
				return window.localStorage.getItem( key );
			} else {
				window.localStorage.setItem( key, value );
			}
		} catch ( e ) {
			return null;
		}

		return null;
	}

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

	/**
	 * تبدیل لینک‌های داخل متن به تگ a — بدون innerHTML تا XSS پیش نیاید.
	 */
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
			headers: {}
		};

		// نانس فقط برای کاربران وارد شده فرستاده می‌شود تا نامشان خودکار ثبت شود.
		if ( data.nonce ) {
			init.headers[ 'X-WP-Nonce' ] = data.nonce;
		}

		if ( options.body ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.body );
		}

		return window.fetch( data.api + path, init ).then( function ( response ) {
			if ( response.ok ) {
				return response.json();
			}

			// اگر صفحه کش شده باشد نانس منقضی است؛ یک بار بدون نانس دوباره تلاش می‌کنیم.
			if ( 403 === response.status && data.nonce && ! options.retried ) {
				data.nonce = '';
				options.retried = true;

				return request( path, options );
			}

			throw new Error( 'request_failed' );
		} );
	}

	/* ---------------------------------------------------------------- */
	/* ساخت رابط کاربری                                                 */
	/* ---------------------------------------------------------------- */

	function buildLauncher() {
		var button = make( 'button', 'cs-launcher' );
		button.type = 'button';
		button.setAttribute( 'aria-label', data.i18n.open );
		button.title = data.i18n.open;
		button.innerHTML =
			'<span class="cs-icon cs-icon-chat"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.99 3 3 6.36 3 10.5c0 2.3 1.24 4.35 3.2 5.72-.14 1.16-.6 2.2-1.36 3.1-.2.24-.06.6.25.64 1.9.22 3.62-.42 4.94-1.4.94.22 1.94.34 2.97.34 5.01 0 9-3.36 9-7.5S17.01 3 12 3z"/></svg></span>' +
			'<span class="cs-icon cs-icon-close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></span>';

		el.badge = make( 'span', 'cs-badge' );
		el.badge.hidden = true;
		button.appendChild( el.badge );

		button.addEventListener( 'click', toggle );

		return button;
	}

	function buildPanel() {
		var panel = make( 'div', 'cs-panel' );
		panel.hidden = true;
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', data.title );

		// سربرگ
		var header = make( 'div', 'cs-header' );
		var headerText = make( 'div', 'cs-header-text' );
		headerText.appendChild( make( 'strong', null, data.title ) );
		headerText.appendChild( make( 'span', null, data.subtitle ) );

		var close = make( 'button', 'cs-close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', data.i18n.close );
		close.addEventListener( 'click', toggle );

		header.appendChild( headerText );
		header.appendChild( close );

		// بدنه‌ی پیام‌ها
		el.body = make( 'div', 'cs-body' );

		if ( data.welcome ) {
			el.body.appendChild( make( 'div', 'cs-welcome', data.welcome ) );
		}

		// فرم پیش از چت
		el.form = buildPreChatForm();

		// نوار ارسال
		el.footer = make( 'div', 'cs-footer' );

		el.input = make( 'textarea', 'cs-input' );
		el.input.rows = 1;
		el.input.placeholder = data.i18n.placeholder;
		el.input.maxLength = data.maxLength;
		el.input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				sendMessage();
			}
		} );
		el.input.addEventListener( 'input', function () {
			el.input.style.height = 'auto';
			el.input.style.height = Math.min( el.input.scrollHeight, 96 ) + 'px';
		} );

		el.send = make( 'button', 'cs-send' );
		el.send.type = 'button';
		el.send.setAttribute( 'aria-label', data.i18n.send );
		el.send.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 21l21-9L2 3v7l15 2-15 2v7z"/></svg>';
		el.send.addEventListener( 'click', sendMessage );

		el.footer.appendChild( el.input );
		el.footer.appendChild( el.send );

		panel.appendChild( header );
		panel.appendChild( el.body );
		panel.appendChild( el.form );
		panel.appendChild( el.footer );

		return panel;
	}

	function buildPreChatForm() {
		var form = make( 'form', 'cs-form' );
		form.hidden = true;

		form.appendChild( make( 'p', null, data.i18n.intro ) );

		el.formError = make( 'p', 'cs-error' );
		el.formError.hidden = true;
		form.appendChild( el.formError );

		if ( data.requireName ) {
			el.nameInput = make( 'input' );
			el.nameInput.type = 'text';
			el.nameInput.placeholder = data.i18n.name;
			el.nameInput.maxLength = 100;
			form.appendChild( el.nameInput );
		}

		if ( data.requireEmail ) {
			el.emailInput = make( 'input' );
			el.emailInput.type = 'email';
			el.emailInput.placeholder = data.i18n.email;
			el.emailInput.maxLength = 100;
			form.appendChild( el.emailInput );
		}

		var submit = make( 'button', 'cs-btn', data.i18n.start );
		submit.type = 'submit';
		form.appendChild( submit );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submitPreChat();
		} );

		return form;
	}

	/* ---------------------------------------------------------------- */
	/* نمایش پیام‌ها                                                     */
	/* ---------------------------------------------------------------- */

	function appendMessage( message ) {
		if ( message.id <= state.lastId ) {
			return;
		}

		state.lastId = message.id;

		var wrap = make( 'div', 'cs-msg cs-msg-' + ( 'admin' === message.sender ? 'admin' : 'visitor' ) );
		var bubble = make( 'div', 'cs-bubble' );

		linkify( bubble, message.message );

		var who = 'admin' === message.sender ? ( message.author || data.agentName ) : data.i18n.you;

		wrap.appendChild( bubble );
		wrap.appendChild( make( 'div', 'cs-meta', who + ' · ' + message.time ) );

		el.body.appendChild( wrap );
	}

	function scrollDown( smooth ) {
		if ( smooth && 'scrollTo' in el.body ) {
			try {
				el.body.scrollTo( { top: el.body.scrollHeight, behavior: 'smooth' } );
				return;
			} catch ( e ) {
				// مرورگر قدیمی: به حالت ساده برمی‌گردیم.
			}
		}

		el.body.scrollTop = el.body.scrollHeight;
	}

	function renderMessages( messages ) {
		if ( ! messages || ! messages.length ) {
			return false;
		}

		messages.forEach( appendMessage );
		scrollDown( state.started );

		return true;
	}

	function setUnread( count ) {
		var isNew = count > state.unread;

		state.unread = count;

		if ( count > 0 && ! state.open ) {
			el.badge.textContent = count > 9 ? '+9' : String( count );
			el.badge.hidden = false;

			if ( isNew ) {
				nudgeLauncher();
			}
		} else {
			el.badge.hidden = true;
		}
	}

	/** یک تکان کوتاه تا کاربر متوجه پیام تازه شود. */
	function nudgeLauncher() {
		el.launcher.classList.remove( 'cs-nudge' );

		// خواندن offsetWidth انیمیشن را از اول اجرا می‌کند حتی اگر کلاس قبلاً بوده باشد.
		void el.launcher.offsetWidth;
		el.launcher.classList.add( 'cs-nudge' );

		window.setTimeout( function () {
			el.launcher.classList.remove( 'cs-nudge' );
		}, 700 );
	}

	function showChatUi() {
		state.started = true;
		el.form.hidden = true;
		el.body.hidden = false;
		el.footer.hidden = false;
		scrollDown();
	}

	function showFormUi() {
		state.started = false;
		el.form.hidden = false;
		el.footer.hidden = true;
	}

	/* ---------------------------------------------------------------- */
	/* ارتباط با سرور                                                    */
	/* ---------------------------------------------------------------- */

	function startSession( name, email ) {
		return request( '/session', {
			method: 'POST',
			body: {
				token: state.token || '',
				name: name || '',
				email: email || '',
				page_url: window.location.href
			}
		} ).then( function ( response ) {
			state.token = response.token;
			store( STORAGE_KEY, response.token );
			renderMessages( response.messages );
			showChatUi();

			return response;
		} );
	}

	function submitPreChat() {
		var name = el.nameInput ? el.nameInput.value.trim() : '';
		var email = el.emailInput ? el.emailInput.value.trim() : '';

		if ( data.requireName && ! name ) {
			return showFormError( data.i18n.nameError );
		}

		if ( data.requireEmail && ( ! email || email.indexOf( '@' ) < 1 ) ) {
			return showFormError( data.i18n.emailError );
		}

		el.formError.hidden = true;

		startSession( name, email ).then( function () {
			el.input.focus();
		} ).catch( function () {
			showFormError( data.i18n.error );
		} );
	}

	function showFormError( text ) {
		el.formError.textContent = text;
		el.formError.hidden = false;
	}

	function sendMessage() {
		var text = el.input.value.trim();

		if ( ! text || state.sending ) {
			return;
		}

		state.sending = true;
		el.send.disabled = true;

		var send = function () {
			return request( '/message', {
				method: 'POST',
				body: { token: state.token, message: text }
			} );
		};

		// اگر هنوز گفتگویی ساخته نشده (فرم پیش از چت خاموش است) اول بسازیم.
		var chain = state.token ? send() : startSession( '', '' ).then( send );

		chain.then( function ( response ) {
			el.input.value = '';
			el.input.style.height = 'auto';
			renderMessages( [ response.message ] );
		} ).catch( function () {
			showTransientError();
		} ).then( function () {
			state.sending = false;
			el.send.disabled = false;
			el.input.focus();
		} );
	}

	function showTransientError() {
		var note = make( 'div', 'cs-meta', data.i18n.error );
		note.style.textAlign = 'center';
		el.body.appendChild( note );
		scrollDown();

		window.setTimeout( function () {
			if ( note.parentNode ) {
				note.parentNode.removeChild( note );
			}
		}, 4000 );
	}

	function poll() {
		if ( ! state.token ) {
			return;
		}

		var query = '/messages?token=' + encodeURIComponent( state.token ) + '&after=' + state.lastId;

		if ( state.open ) {
			query += '&open=1';
		}

		request( query ).then( function ( response ) {
			var added = renderMessages( response.messages );

			if ( state.open ) {
				setUnread( 0 );
			} else if ( added || response.unread !== state.unread ) {
				setUnread( response.unread );
			}
		} ).catch( function () {
			// خطای شبکه را بی‌صدا رد می‌کنیم؛ دور بعدی دوباره تلاش می‌شود.
		} );
	}

	function schedulePolling() {
		window.clearInterval( state.timer );
		state.timer = window.setInterval( poll, state.open ? POLL_OPEN : POLL_IDLE );
	}

	/* ---------------------------------------------------------------- */
	/* باز و بسته کردن                                                   */
	/* ---------------------------------------------------------------- */

	/**
	 * پنجره را با انیمیشن باز یا بسته می‌کند.
	 *
	 * صفت hidden باعث display:none می‌شود و انیمیشن را می‌کشد، پس هنگام باز شدن
	 * اول آن را برمی‌داریم و یک فریم بعد کلاس را می‌زنیم، و هنگام بستن برعکس.
	 */
	function setPanelVisible( visible ) {
		window.clearTimeout( state.animTimer );

		if ( visible ) {
			el.panel.hidden = false;
			el.launcher.classList.add( 'cs-launcher-open' );
			el.launcher.setAttribute( 'aria-label', data.i18n.close );

			window.requestAnimationFrame( function () {
				el.panel.classList.add( 'cs-panel-open' );
			} );
		} else {
			el.panel.classList.remove( 'cs-panel-open' );
			el.launcher.classList.remove( 'cs-launcher-open' );
			el.launcher.setAttribute( 'aria-label', data.i18n.open );

			state.animTimer = window.setTimeout( function () {
				el.panel.hidden = true;
			}, 260 );
		}
	}

	function toggle() {
		state.open = ! state.open;
		setPanelVisible( state.open );

		if ( state.open ) {
			setUnread( 0 );

			if ( state.started ) {
				scrollDown();
				el.input.focus();
			} else if ( state.token ) {
				startSession();
			} else if ( data.requireName || data.requireEmail ) {
				showFormUi();
			} else {
				showChatUi();
				el.input.focus();
			}

			poll();
		}

		schedulePolling();
	}

	/* ---------------------------------------------------------------- */
	/* راه‌اندازی                                                        */
	/* ---------------------------------------------------------------- */

	function init() {
		var root = document.getElementById( 'chat-support-root' );

		root.className = 'cs-pos-' + ( 'left' === data.position ? 'left' : 'right' );
		root.style.setProperty( '--cs-color', data.color );
		root.dir = data.rtl ? 'rtl' : 'ltr';

		el.panel = buildPanel();
		el.launcher = buildLauncher();

		root.appendChild( el.panel );
		root.appendChild( el.launcher );

		// انیمیشن ورود فقط یک بار؛ بعد برداشته می‌شود تا مزاحم انیمیشن‌های بعدی نشود.
		el.launcher.classList.add( 'cs-launcher-enter' );
		window.setTimeout( function () {
			el.launcher.classList.remove( 'cs-launcher-enter' );
		}, 450 );

		state.token = store( STORAGE_KEY );

		if ( state.token ) {
			// گفتگوی قبلی وجود دارد: پیام‌ها را بگیر ولی پنجره را باز نکن.
			request( '/messages?token=' + encodeURIComponent( state.token ) + '&after=0' ).then( function ( response ) {
				renderMessages( response.messages );
				showChatUi();
				setUnread( response.unread );
			} ).catch( function () {
				// توکن نامعتبر شده (مثلاً گفتگو حذف شده) — از نو شروع می‌کنیم.
				state.token = null;
				store( STORAGE_KEY, null );
			} );
		}

		schedulePolling();

		// وقتی کاربر به تب برمی‌گردد، بلافاصله چک کن.
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				poll();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
