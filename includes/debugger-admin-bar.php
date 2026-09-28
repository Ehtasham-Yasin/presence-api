<?php
/**
 * Admin bar debugger: the next Heartbeat, and every client in the rooms the current user is in.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the debugger node to the admin bar.
 *
 * @since 0.12.0
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_debugger_admin_bar_node( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$wp_admin_bar->add_node(
		array(
			'parent' => 'top-secondary',
			'id'     => 'presence-debug',
			'title'  => '<span class="ab-icon" aria-hidden="true"></span>'
				. '<span class="presence-debug-countdown" aria-hidden="true"></span>'
				. '<span class="screen-reader-text">' . esc_html__( 'Presence API Debugger', 'presence-api' ) . '</span>',
			'meta'   => array(
				'class'    => 'menupop',
				'tabindex' => 0,
			),
		)
	);

	$rows = array(
		'interval' => __( 'Interval', 'presence-api' ),
		'ttl'      => __( 'TTL', 'presence-api' ),
	);
	foreach ( $rows as $key => $label ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug',
				'id'     => 'presence-debug-' . $key,
				'title'  => '<span>' . esc_html( $label ) . '</span><span class="presence-debug-value" data-presence-debug="' . esc_attr( $key ) . '"' . ( 'ttl' === $key ? ' data-presence-debug-seconds="' . esc_attr( wp_presence_get_timeout() ) . '"' : '' ) . '></span>',
				'meta'   => array( 'class' => 'presence-debug-row' ),
			)
		);
	}

	global $wpdb;

	$rooms = array();
	if ( wp_presence_has_table() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rooms = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT room FROM {$wpdb->presence} WHERE user_id = %d AND expires_gmt > %s ORDER BY room",
				get_current_user_id(),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	foreach ( $rooms as $i => $room ) {
		$group = 'presence-debug-room-' . $i;
		$wp_admin_bar->add_group(
			array(
				'parent' => 'presence-debug',
				'id'     => $group,
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);
		$wp_admin_bar->add_node(
			array(
				'parent' => $group,
				'id'     => $group . '-name',
				'title'  => '<code>' . esc_html( $room ) . '</code>',
				'meta'   => array( 'class' => 'presence-debug-room' ),
			)
		);

		// Reserved rows included, since the plugin's own bookkeeping is part of what is being debugged.
		$rows = wp_presence_room_rows( $room );
		foreach ( array_slice( $rows, 0, 20 ) as $j => $row ) {
			$user = get_userdata( (int) $row->user_id );
			$age  = max( 0, time() - (int) strtotime( $row->date_gmt . ' UTC' ) );
			$wp_admin_bar->add_node(
				array(
					'parent' => $group,
					'id'     => $group . '-' . $j,
					'title'  => '<span>' . esc_html( $user ? $user->display_name : '#' . $row->user_id ) . ' <code>' . esc_html( $row->client_id ) . '</code></span><span class="presence-debug-value" data-presence-debug-age="' . esc_attr( $age ) . '"></span>',
					'meta'   => array( 'class' => 'presence-debug-row' ),
				)
			);
		}
		if ( count( $rows ) > 20 ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => $group,
					'id'     => $group . '-more',
					/* translators: %s: Number of clients not listed. */
					'title'  => esc_html( sprintf( __( '+%s more', 'presence-api' ), number_format_i18n( count( $rows ) - 20 ) ) ),
					'meta'   => array( 'class' => 'presence-debug-more' ),
				)
			);
		}
	}

	if ( file_exists( __DIR__ . '/db-viewer.php' ) ) {
		$wp_admin_bar->add_group(
			array(
				'parent' => 'presence-debug',
				'id'     => 'presence-debug-tools',
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug-tools',
				'id'     => 'presence-debug-table',
				'title'  => esc_html__( 'View the presence table', 'presence-api' )
					. '<span class="dashicons dashicons-external" aria-hidden="true"></span>'
					. '<span class="screen-reader-text">' . esc_html__( '(opens in a new window)', 'presence-api' ) . '</span>',
				'href'   => wp_nonce_url( home_url( '/?presence-db=1' ), 'wp_presence_db_viewer' ),
				'meta'   => array(
					'class'  => 'presence-debug-table',
					'target' => '_blank',
				),
			)
		);
	}
}

/**
 * Renders the debugger node on its own, for the heartbeat to swap in.
 *
 * @since 0.12.0
 *
 * @return string The node's list item markup.
 */
function wp_presence_debugger_admin_bar_markup() {
	require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

	$bar = new class() extends WP_Admin_Bar {
		/**
		 * Renders one top-level node.
		 *
		 * @since 0.12.0
		 *
		 * @param string $id Node ID.
		 * @return string The node's list item markup.
		 */
		public function render_node( $id ) {
			$node = $this->_get_node( $id );
			if ( ! $node ) {
				return '';
			}
			$this->_bind();
			ob_start();
			$this->_render_item( $node );
			return (string) ob_get_clean();
		}
	};

	wp_presence_debugger_admin_bar_node( $bar );

	return $bar->render_node( 'presence-debug' );
}

/**
 * Sends a fresh debugger node with each heartbeat that asks for one.
 *
 * @since 0.12.0
 *
 * @param array $response Heartbeat response data.
 * @param array $data     Data received from the client.
 * @return array The Heartbeat response.
 */
function wp_presence_debugger_heartbeat_received( $response, $data ) {
	if ( ! wp_presence_fragment_request( $data, 'debugger' ) || ! current_user_can( 'manage_options' ) ) {
		return $response;
	}

	$response['presence-fragments']['debugger'] = wp_presence_debugger_admin_bar_markup();

	return $response;
}

/**
 * Enqueues the debugger node's styles and heartbeat readout.
 *
 * @since 0.12.0
 */
function wp_presence_debugger_admin_bar_assets() {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$css = '
		#wp-admin-bar-presence-debug > .ab-item { display: flex !important; align-items: center; gap: 6px; cursor: default; }
		#wp-admin-bar-presence-debug .ab-icon { margin: 0 !important; }
		#wpadminbar #wp-admin-bar-presence-debug .ab-icon::before { content: "\\f487"; top: 2px; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned > .ab-item { color: var(--presence-debug-open-color); background: var(--presence-debug-open-background); }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned .ab-icon::before { color: inherit; }
		#wpadminbar #wp-admin-bar-presence-debug.is-beating .ab-icon::before { color: #d63638; }
		#wpadminbar #wp-admin-bar-presence-debug.is-lost .ab-icon::before { color: #dba617; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned > .ab-item::after { content: "\\f537"; font: 16px/32px dashicons; margin-inline-start: 6px; vertical-align: top; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-debug.is-lost .ab-icon::before { color: #996800; }
		#wp-admin-bar-presence-debug .presence-debug-countdown { min-width: 2.5em; font-variant-numeric: tabular-nums; }
		#wp-admin-bar-presence-debug.is-beating .ab-icon { animation: presence-debug-heart 2s cubic-bezier(0.22, 0.61, 0.36, 1); }
		@keyframes presence-debug-heart { 0% { transform: scale(1); } 5% { transform: scale(1.45); } 14% { transform: scale(0.92); } 22% { transform: scale(1.3); } 32% { transform: scale(0.97); } 42%, 100% { transform: scale(1); } }
		#wpadminbar #wp-admin-bar-presence-debug > .ab-sub-wrapper { min-width: 320px; max-height: calc(100vh - 64px); overflow-y: auto; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned > .ab-sub-wrapper { display: block; }
		#wpadminbar #wp-admin-bar-presence-debug .ab-submenu .ab-item { display: flex; align-items: center; min-height: 26px; }
		#wpadminbar #wp-admin-bar-presence-debug .ab-submenu .ab-item, #wpadminbar #wp-admin-bar-presence-debug .ab-submenu .ab-item > * { line-height: 1.4; }
		#wp-admin-bar-presence-debug .presence-debug-row > .ab-item { gap: 16px; cursor: default; }
		#wp-admin-bar-presence-debug .presence-debug-value { margin-inline-start: auto; font-variant-numeric: tabular-nums; opacity: .8; }
		.admin-color-light #wp-admin-bar-presence-debug .presence-debug-value { opacity: 1; color: #646970; }
		#wp-admin-bar-presence-debug .presence-debug-table .dashicons { font: 16px/1 dashicons; margin-inline-start: 4px; vertical-align: text-bottom; }
		#wp-admin-bar-presence-debug code { padding: 0; background: none; color: inherit; font-size: 12px; line-height: inherit; }
		#wp-admin-bar-presence-debug .presence-debug-room > .ab-item { font-weight: 600; cursor: default; }
		#wp-admin-bar-presence-debug .presence-debug-more > .ab-item { opacity: .8; cursor: default; }
		.admin-color-light #wp-admin-bar-presence-debug .presence-debug-more > .ab-item { opacity: 1; color: #646970; }
		@media (prefers-reduced-motion: reduce) { #wp-admin-bar-presence-debug.is-beating .ab-icon { animation: none; } }
	';

	$i18n = array(
		'fast'       => __( 'fast', 'presence-api' ),
		'background' => __( 'background or idle', 'presence-api' ),
		'suspended'  => __( 'Suspended', 'presence-api' ),
		'lost'       => __( 'Connection lost', 'presence-api' ),
	);

	// Intl spells out the units and plurals in the page's own language.
	$js = sprintf(
		'( function ( $ ) {
			const node = document.getElementById( "wp-admin-bar-presence-debug" );
			if ( ! node || ! window.wp || ! wp.heartbeat ) {
				return;
			}
			const i18n = %s;
			const lang = document.documentElement.lang || undefined;
			const seconds = new Intl.NumberFormat( lang, { style: "unit", unit: "second", unitDisplay: "narrow" } );
			let lastSend = Date.now();
			let table = null;

			function render() {
				// Core waits 120 seconds between beats while the window is in the background or the user is idle.
				const focused = wp.heartbeat.hasFocus();
				const period = focused ? wp.heartbeat.interval() : 120;
				const elapsed = ( Date.now() - lastSend ) / 1000;
				const left = Math.ceil( period - elapsed );
				// Only a suspended Heartbeat stops sending; a lost connection keeps retrying on schedule.
				const state = left < -10 ? "suspended" : ( wp.heartbeat.hasConnectionError() ? "lost" : "" );
				node.classList.toggle( "is-lost", "lost" === state );
				node.classList.toggle( "is-suspended", "suspended" === state );
				node.querySelector( ".presence-debug-countdown" ).textContent = state ? i18n[ state ] : seconds.format( Math.max( 0, left ) );
				node.querySelectorAll( "[data-presence-debug]" ).forEach( function ( el ) {
					const key = el.dataset.presenceDebug;
					if ( "interval" === key ) {
						const mode = focused ? ( 5 === period ? i18n.fast : "" ) : i18n.background;
						el.textContent = seconds.format( period ) + ( mode ? " (" + mode + ")" : "" );
					} else if ( el.dataset.presenceDebugSeconds ) {
						el.textContent = seconds.format( +el.dataset.presenceDebugSeconds );
					}
				} );
				node.querySelectorAll( "[data-presence-debug-age]" ).forEach( function ( el ) {
					el.dataset.presenceDebugT0 = el.dataset.presenceDebugT0 || Date.now();
					el.textContent = seconds.format( Math.round( +el.dataset.presenceDebugAge + ( Date.now() - el.dataset.presenceDebugT0 ) / 1000 ) );
				} );
			}

			// The heart beats when the server answers, so a failed request leaves it still.
			$( document ).on( "heartbeat-tick", function () {
				// The viewer has no Heartbeat of its own, so each beat here refreshes it.
				if ( table && ! table.closed ) {
					table.location.reload();
				}
				node.classList.remove( "is-beating" );
				void node.offsetWidth;
				node.classList.add( "is-beating" );
			} );
			node.addEventListener( "animationend", function () {
				node.classList.remove( "is-beating" );
			} );
			$( document ).on( "heartbeat-connection-lost heartbeat-connection-restored", render );
			$( document ).on( "heartbeat-send", function () {
				lastSend = Date.now();
				render();
			} );
			render();
			setInterval( render, 1000 );

			// A pinned menu stays open, so it slides aside for any other menu that opens over it.
			function reposition() {
				const menu = node.querySelector( ".ab-sub-wrapper" );
				if ( ! menu ) {
					return;
				}
				menu.style.right = "";
				if ( ! node.classList.contains( "is-pinned" ) ) {
					return;
				}
				const open = document.querySelector( "#wpadminbar li.menupop.hover:not(#wp-admin-bar-presence-debug) > .ab-sub-wrapper" );
				if ( ! open ) {
					return;
				}
				const mine = menu.getBoundingClientRect();
				const theirs = open.getBoundingClientRect();
				if ( mine.right > theirs.left && mine.left < theirs.right ) {
					menu.style.right = ( mine.right - theirs.left + 8 ) + "px";
				}
			}
			new MutationObserver( function () {
				window.requestAnimationFrame( reposition );
			} ).observe( document.getElementById( "wpadminbar" ), { attributes: true, attributeFilter: [ "class" ], subtree: true } );

			// Core applies the open-menu colors only while hovered, so a probe borrows them.
			const probe = document.createElement( "li" );
			probe.className = "menupop hover";
			probe.appendChild( document.createElement( "span" ) ).className = "ab-item";
			document.getElementById( "wp-admin-bar-top-secondary" ).appendChild( probe );
			const open = window.getComputedStyle( probe.firstChild );
			node.style.setProperty( "--presence-debug-open-color", open.color );
			node.style.setProperty( "--presence-debug-open-background", open.backgroundColor );
			probe.remove();

			function pin( pinned ) {
				node.classList.toggle( "is-pinned", pinned );
				node.firstElementChild.setAttribute( "aria-expanded", pinned ? "true" : "false" );
				try {
					window.localStorage.setItem( "presence-debug-pinned", pinned ? "1" : "" );
				} catch ( e ) {}
			}
			try {
				pin( "1" === window.localStorage.getItem( "presence-debug-pinned" ) );
			} catch ( e ) {}
			node.firstElementChild.addEventListener( "click", function ( event ) {
				pin( ! node.classList.contains( "is-pinned" ) );
				// Live updates skip a focused menu, so a pinned one would stop refreshing.
				if ( event.detail ) {
					this.blur();
				}
			} );

			// Delegated, since each beat swaps the menu.
			$( node ).on( "click", ".presence-debug-table > a", function ( event ) {
				table = window.open( this.href, "presence-db", "popup,width=960,height=640" );
				if ( table ) {
					event.preventDefault();
				}
			} );

			if ( wp.hooks ) {
				wp.hooks.addFilter( "presence-api.liveSurfaces", "presence-api/debugger", function ( surfaces ) {
					surfaces.push( {
						key: "debugger",
						target: function () {
							return document.getElementById( "wp-admin-bar-presence-debug" );
						},
						// Only the menu is swapped, so the heart keeps beating mid-animation.
						apply: function ( element, html ) {
							const template = document.createElement( "template" );
							template.innerHTML = html.trim();
							const fresh = template.content.querySelector( ".ab-sub-wrapper" );
							const menu = element.querySelector( ".ab-sub-wrapper" );
							if ( fresh && menu ) {
								menu.replaceWith( fresh );
								render();
								reposition();
							}
						},
					} );
					return surfaces;
				} );
			}
		} )( jQuery );',
		wp_json_encode( $i18n )
	);

	wp_register_style( 'presence-debugger-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-debugger-admin-bar' );
	wp_add_inline_style( 'presence-debugger-admin-bar', $css );

	wp_register_script( 'presence-debugger-admin-bar', false, array( 'jquery', 'heartbeat', 'wp-hooks' ), WP_PRESENCE_VERSION, true );
	wp_enqueue_script( 'presence-debugger-admin-bar' );
	wp_add_inline_script( 'presence-debugger-admin-bar', $js );
}
