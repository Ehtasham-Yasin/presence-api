<?php
/**
 * Scene director: registered scenes cast marked users, cue what they do on the debugging tab's Heartbeat, then strike them.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a scene for the debugger to direct.
 *
 * @since 0.12.0
 *
 * @param string $name Namespaced scene name, such as `my-plugin/review-queue`.
 * @param array  $args {
 *     Scene arguments.
 *
 *     @type string $label    Button label.
 *     @type array  $cast     One to five parts, each `array( 'role' => 'author' )`, cast in order as Alice, Bob, Carol, Dave and Erin.
 *     @type array  $cues     Each `array( 'after' => seconds, 'label' => string, 'callback' => callable )`. The callback receives the cast as WP_Presence_Scene_Actor objects.
 *     @type int    $duration Optional. Seconds before the strike. Default five after the last cue.
 * }
 * @return bool Whether the scene was registered.
 */
function wp_register_presence_scene( $name, $args ) {
	global $wp_presence_scenes;

	if ( ! doing_action( 'wp_presence_scenes_init' ) ) {
		/* translators: %s: Action name. */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'Scenes must be registered on the %s action.', 'presence-api' ), '<code>wp_presence_scenes_init</code>' ), '0.12.0' );
		return false;
	}

	if ( ! is_string( $name ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Scene names must be a namespace and a name, lowercase with dashes, such as my-plugin/review-queue.', 'presence-api' ), '0.12.0' );
		return false;
	}

	$args = wp_parse_args(
		$args,
		array(
			'label'    => $name,
			'cast'     => array(),
			'cues'     => array(),
			'duration' => 0,
		)
	);

	if ( ! $args['cast'] || count( $args['cast'] ) > 5 ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'A scene casts one to five actors.', 'presence-api' ), '0.12.0' );
		return false;
	}

	$last = 0;
	foreach ( $args['cues'] as $cue ) {
		if ( ! isset( $cue['after'], $cue['label'], $cue['callback'] ) || ! is_callable( $cue['callback'] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Each cue needs an after, a label and a callback.', 'presence-api' ), '0.12.0' );
			return false;
		}
		$last = max( $last, (int) $cue['after'] );
	}

	$args['duration'] = max( (int) $args['duration'], $last + 5 );

	$wp_presence_scenes[ $name ] = $args;

	return true;
}

/**
 * Returns every registered scene, firing the registration action on first use.
 *
 * @since 0.12.0
 *
 * @return array Scenes keyed by name.
 */
function wp_get_presence_scenes() {
	global $wp_presence_scenes;

	if ( ! did_action( 'wp_presence_scenes_init' ) ) {
		$wp_presence_scenes = array();

		/**
		 * Fires when the debugger first needs its scenes, for wp_register_presence_scene() calls.
		 *
		 * @since 0.12.0
		 */
		do_action( 'wp_presence_scenes_init' );
	}

	return (array) $wp_presence_scenes;
}

/**
 * Fails the current cue unless a condition holds.
 *
 * @since 0.12.0
 *
 * @param bool   $condition What the scene expects to be true.
 * @param string $message   What went wrong when it is not.
 *
 * @throws UnexpectedValueException When the condition is false.
 */
function wp_presence_scene_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new UnexpectedValueException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
	}
}

/**
 * Adds a note to the running scene's report, once per distinct problem.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array  $run     The running scene.
 * @param string $level   One of pass, fail, warning or info.
 * @param string $message The note.
 */
function wp_presence_scene_note( array &$run, $level, $message ) {
	if ( 'fail' === $level || 'warning' === $level ) {
		foreach ( $run['notes'] as $note ) {
			if ( $note['level'] === $level && $note['message'] === $message ) {
				return;
			}
		}
	}

	$run['notes'][] = array(
		't'       => max( 0, time() - $run['started'] ),
		'level'   => $level,
		'message' => $message,
	);
}

/**
 * Casts a scene and plays its opening cues.
 *
 * @since 0.12.0
 *
 * @param string $name Scene name.
 * @return bool Whether the scene started.
 */
function wp_presence_scene_start( $name ) {
	$scenes = wp_get_presence_scenes();

	if ( ! isset( $scenes[ $name ] ) ) {
		return false;
	}

	wp_presence_scene_sweep( true );

	$scene  = $scenes[ $name ];
	$number = (int) get_option( 'wp_presence_scene_runs', 1000 ) + 1;
	update_option( 'wp_presence_scene_runs', $number, false );

	$run = array(
		'name'    => $name,
		'label'   => $scene['label'],
		'run'     => $number,
		'started' => time(),
		'expires' => time() + $scene['duration'] + 10 * MINUTE_IN_SECONDS,
		'cast'    => array(),
		'posts'   => array(),
		'done'    => array(),
		'beats'   => array(),
		'notes'   => array(),
	);

	$names = array( 'Alice', 'Bob', 'Carol', 'Dave', 'Erin' );
	foreach ( array_values( $scene['cast'] ) as $i => $part ) {
		$login   = strtolower( $names[ $i ] ) . $number;
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.com',
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $names[ $i ],
				'first_name'   => $names[ $i ],
				'role'         => $part['role'] ?? 'author',
				'meta_input'   => array( '_wp_presence_scene' => $run['expires'] ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_presence_scene_note( $run, 'fail', $user_id->get_error_message() );
			wp_presence_scene_strike( $run );
			return false;
		}

		$run['cast'][] = $user_id;
	}

	delete_option( 'wp_presence_scene_notes' );
	update_option( 'wp_presence_scene', $run, false );

	wp_presence_scene_direct();

	return true;
}

/**
 * Plays every cue that is due, keeps the cast beating, and strikes a finished scene.
 *
 * @since 0.12.0
 *
 * @return array|null The running scene, or null when none is running.
 */
function wp_presence_scene_direct() {
	$run = get_option( 'wp_presence_scene' );

	if ( ! is_array( $run ) ) {
		return null;
	}

	// Two tabs beating at once would otherwise play a cue twice.
	if ( ! add_option( 'wp_presence_scene_lock', time(), '', false ) ) {
		if ( time() - (int) get_option( 'wp_presence_scene_lock' ) < 30 ) {
			return $run;
		}
		update_option( 'wp_presence_scene_lock', time(), false );
	}

	require_once ABSPATH . 'wp-admin/includes/post.php';

	$scenes = wp_get_presence_scenes();

	if ( ! isset( $scenes[ $run['name'] ] ) ) {
		wp_presence_scene_note( $run, 'fail', __( 'The scene is no longer registered.', 'presence-api' ) );
		wp_presence_scene_strike( $run );
		delete_option( 'wp_presence_scene_lock' );
		return null;
	}

	$scene   = $scenes[ $run['name'] ];
	$elapsed = time() - $run['started'];
	$cast    = array();
	foreach ( $run['cast'] as $user_id ) {
		$cast[] = new WP_Presence_Scene_Actor( $user_id, $run );
	}

	foreach ( $scene['cues'] as $i => $cue ) {
		if ( isset( $run['done'][ $i ] ) || (int) $cue['after'] > $elapsed ) {
			continue;
		}
		$run['done'][ $i ] = true;

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collects the cue's warnings for the report.
		set_error_handler(
			function ( $errno, $errstr, $file, $line ) use ( &$warnings ) {
				$warnings[] = sprintf( '%s (%s:%d)', $errstr, wp_basename( $file ), $line );
				return true;
			}
		);

		try {
			call_user_func( $cue['callback'], $cast );
			wp_presence_scene_note( $run, 'pass', $cue['label'] );
		} catch ( Throwable $e ) {
			wp_presence_scene_note( $run, 'fail', $cue['label'] . ': ' . $e->getMessage() );
		} finally {
			restore_error_handler();
		}

		foreach ( $warnings as $warning ) {
			wp_presence_scene_note( $run, 'warning', $warning );
		}
	}

	foreach ( $cast as $actor ) {
		foreach ( $actor->beat_again() as $problem ) {
			wp_presence_scene_note( $run, $problem[0], $problem[1] );
		}
	}

	delete_option( 'wp_presence_scene_lock' );

	if ( count( $run['done'] ) === count( $scene['cues'] ) && $elapsed >= $scene['duration'] ) {
		wp_presence_scene_strike( $run );
		return null;
	}

	update_option( 'wp_presence_scene', $run, false );

	return $run;
}

/**
 * Deletes the cast and everything they made, then checks nothing is left behind.
 *
 * @since 0.12.0
 *
 * @param array $run The running scene.
 */
function wp_presence_scene_strike( array $run ) {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}

	// Deleting a user only trashes their posts, so the scene's own go first.
	foreach ( $run['posts'] as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	foreach ( $run['cast'] as $user_id ) {
		if ( ! ( is_multisite() ? wpmu_delete_user( $user_id ) : wp_delete_user( $user_id ) ) ) {
			/* translators: %d: User ID. */
			wp_presence_scene_note( $run, 'fail', sprintf( __( 'Could not delete user %d.', 'presence-api' ), $user_id ) );
		}
	}

	foreach ( $run['cast'] as $user_id ) {
		if ( get_userdata( $user_id ) ) {
			/* translators: %d: User ID. */
			wp_presence_scene_note( $run, 'fail', sprintf( __( 'User %d is still there after the strike.', 'presence-api' ), $user_id ) );
		}
		if ( wp_presence_has_table() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->presence} WHERE user_id = %d", $user_id ) );
			if ( $left ) {
				/* translators: 1: Number of rows, 2: User ID. */
				wp_presence_scene_note( $run, 'fail', sprintf( _n( '%1$s presence row is left for user %2$d.', '%1$s presence rows are left for user %2$d.', $left, 'presence-api' ), number_format_i18n( $left ), $user_id ) );
			}
		}
	}

	foreach ( $run['posts'] as $post_id ) {
		if ( get_post( $post_id ) ) {
			/* translators: %d: Post ID. */
			wp_presence_scene_note( $run, 'fail', sprintf( __( 'Post %d is still there after the strike.', 'presence-api' ), $post_id ) );
		}
	}

	$problems = count(
		array_filter(
			$run['notes'],
			function ( $note ) {
				return 'fail' === $note['level'] || 'warning' === $note['level'];
			}
		)
	);

	wp_presence_scene_note(
		$run,
		$problems ? 'fail' : 'pass',
		$problems
			/* translators: %s: Number of problems. */
			? sprintf( _n( 'Curtain: %s problem.', 'Curtain: %s problems.', $problems, 'presence-api' ), number_format_i18n( $problems ) )
			: __( 'Curtain: nothing went wrong.', 'presence-api' )
	);

	delete_option( 'wp_presence_scene' );
	update_option(
		'wp_presence_scene_notes',
		array(
			'run'   => $run['run'],
			'name'  => $run['name'],
			'label' => $run['label'],
			'notes' => $run['notes'],
		),
		false
	);
}

/**
 * Strikes an abandoned scene and deletes any cast member past their expiry.
 *
 * @since 0.12.0
 *
 * @param bool $all Optional. Strike everything regardless of expiry. Default false.
 */
function wp_presence_scene_sweep( $all = false ) {
	$run = get_option( 'wp_presence_scene' );

	if ( is_array( $run ) && ( $all || $run['expires'] < time() ) ) {
		wp_presence_scene_note( $run, 'info', __( 'Struck by the sweep before the scene finished.', 'presence-api' ) );
		wp_presence_scene_strike( $run );
	}

	$query = array(
		'blog_id'  => 0,
		'fields'   => 'ID',
		'meta_key' => '_wp_presence_scene', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);
	if ( ! $all ) {
		$query['meta_value']   = time(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query['meta_compare'] = '<';
		$query['meta_type']    = 'NUMERIC';
	}

	$user_ids = get_users( $query );
	if ( ! $user_ids ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	foreach ( $user_ids as $user_id ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $user_id );
		} else {
			wp_delete_user( $user_id );
		}
	}
}

/**
 * Handles the debugger's start, cut and clear links.
 *
 * @since 0.12.0
 */
function wp_presence_scene_admin_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to direct scenes.', 'presence-api' ), 403 );
	}

	check_admin_referer( 'wp_presence_scene' );

	$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

	if ( 'start' === $do && isset( $_GET['scene'] ) ) {
		wp_presence_scene_start( sanitize_text_field( wp_unslash( $_GET['scene'] ) ) );
	} elseif ( 'cut' === $do ) {
		$run = get_option( 'wp_presence_scene' );
		if ( is_array( $run ) ) {
			wp_presence_scene_note( $run, 'info', __( 'Cut.', 'presence-api' ) );
			wp_presence_scene_strike( $run );
		}
	} elseif ( 'clear' === $do ) {
		delete_option( 'wp_presence_scene_notes' );
	}

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

/**
 * Directs the running scene on each beat of the debugging tab, and returns its notes.
 *
 * @since 0.12.0
 *
 * @param array $response Heartbeat response data.
 * @param array $data     Data received from the client.
 * @return array The Heartbeat response.
 */
function wp_presence_scene_heartbeat_received( $response, $data ) {
	if ( empty( $data['presence-scene'] ) || ! current_user_can( 'manage_options' ) ) {
		return $response;
	}

	$run    = wp_presence_scene_direct();
	$report = $run ? $run : get_option( 'wp_presence_scene_notes' );

	if ( is_array( $report ) ) {
		$response['presence-scene'] = array(
			'run'     => (int) $report['run'],
			'label'   => $report['label'],
			'running' => null !== $run,
			'notes'   => $report['notes'],
		);
	}

	return $response;
}

/**
 * Adds a row per scene to the top of the debugger menu, showing its progress or last result.
 *
 * @since 0.12.0
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_scene_admin_bar_nodes( $wp_admin_bar ) {
	$scenes = wp_get_presence_scenes();

	if ( ! $scenes ) {
		return;
	}

	$run    = get_option( 'wp_presence_scene' );
	$report = is_array( $run ) ? null : get_option( 'wp_presence_scene_notes' );

	$wp_admin_bar->add_group(
		array(
			'parent' => 'presence-debug',
			'id'     => 'presence-debug-scenes',
		)
	);

	foreach ( $scenes as $name => $scene ) {
		$running  = is_array( $run ) && $run['name'] === $name;
		$value    = '';
		$problems = array();

		if ( $running ) {
			/* translators: 1: Cues played, 2: Total cues. */
			$value = sprintf( __( '%1$s / %2$s', 'presence-api' ), number_format_i18n( count( $run['done'] ) ), number_format_i18n( count( $scene['cues'] ) ) );
		} elseif ( is_array( $report ) && ( $report['name'] ?? '' ) === $name ) {
			// The last note is the curtain, which only counts the others.
			$problems = array_filter(
				array_slice( $report['notes'], 0, -1 ),
				function ( $note ) {
					return 'fail' === $note['level'] || 'warning' === $note['level'];
				}
			);
			$value    = $problems
				/* translators: %s: Number of problems. */
				? sprintf( _n( '%s problem', '%s problems', count( $problems ), 'presence-api' ), number_format_i18n( count( $problems ) ) )
				: __( 'No problems', 'presence-api' );
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug-scenes',
				'id'     => 'presence-debug-scene-' . str_replace( '/', '-', $name ),
				'title'  => '<span class="presence-debug-scene-icon' . ( $running ? ' is-running' : '' ) . '" aria-hidden="true"></span>'
					/* translators: %s: Scene label. */
					. '<span><span class="screen-reader-text">' . esc_html( $running ? __( 'Cut', 'presence-api' ) : __( 'Start', 'presence-api' ) ) . ' </span>' . esc_html( $scene['label'] ) . '</span>'
					. ( '' !== $value ? '<span class="presence-debug-value">' . esc_html( $value ) . '</span>' : '' ),
				'href'   => wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'presence_scene',
							'do'     => $running ? 'cut' : 'start',
							'scene'  => $name,
						),
						admin_url( 'admin-post.php' )
					),
					'wp_presence_scene'
				),
				'meta'   => array( 'class' => 'presence-debug-scene' . ( $running ? ' is-running' : '' ) ),
			)
		);

		foreach ( $problems as $i => $note ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-scenes',
					'id'     => 'presence-debug-scene-note-' . $i,
					'title'  => '<span>' . esc_html( $note['message'] ) . '</span><span class="presence-debug-value" data-presence-debug="note" data-presence-debug-seconds="' . esc_attr( $note['t'] ) . '"></span>',
					'meta'   => array( 'class' => 'presence-debug-note' ),
				)
			);
		}
	}
}

/**
 * Adds the scene styles and console report to the debugger's assets.
 *
 * @since 0.12.0
 */
function wp_presence_scene_assets() {
	if ( ! wp_script_is( 'presence-debugger-admin-bar' ) ) {
		return;
	}

	wp_add_inline_style(
		'presence-debugger-admin-bar',
		'#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-icon::before { content: "\\f522"; display: block; width: 16px; margin-inline-end: 8px; font: 16px/1 dashicons; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-icon.is-running::before { content: ""; width: 10px; height: 10px; margin-inline: 3px 11px; background: currentColor; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-note > .ab-item { max-width: 420px; padding-inline-start: 34px; white-space: normal; }'
	);

	wp_add_inline_script(
		'presence-debugger-admin-bar',
		<<<'JS'
( function ( $ ) {
	if ( ! document.getElementById( "wp-admin-bar-presence-debug-scenes" ) || ! window.wp || ! wp.heartbeat ) {
		return;
	}
	const marks = { pass: "✓", fail: "✗", warning: "!", info: "•" };
	const methods = { fail: "error", warning: "warn" };

	$( document ).on( "heartbeat-send", function ( event, data ) {
		data[ "presence-scene" ] = 1;
	} );

	// Printed once per tab, so a reload does not repeat the report.
	$( document ).on( "heartbeat-tick", function ( event, data ) {
		const scene = data[ "presence-scene" ];
		if ( ! scene ) {
			return;
		}
		let printed = 0;
		try {
			const seen = ( window.sessionStorage.getItem( "presence-scene-printed" ) || "" ).split( ":" );
			printed = +seen[ 0 ] === scene.run ? +seen[ 1 ] : 0;
		} catch ( e ) {}
		scene.notes.slice( printed ).forEach( function ( note ) {
			console[ methods[ note.level ] || "log" ]( scene.label + " " + marks[ note.level ] + " " + note.t + "s " + note.message );
		} );
		try {
			window.sessionStorage.setItem( "presence-scene-printed", scene.run + ":" + scene.notes.length );
		} catch ( e ) {}
		// After the idle backoff, which would otherwise widen the interval between cues.
		if ( scene.running ) {
			window.setTimeout( function () {
				wp.heartbeat.interval( "fast" );
			} );
		}
	} );

	if ( document.querySelector( "#wp-admin-bar-presence-debug-scenes .is-running" ) ) {
		wp.heartbeat.interval( "fast" );
		wp.heartbeat.connectNow();
	}
} )( jQuery );
JS
	);
}

/**
 * Registers the built-in scenes.
 *
 * @since 0.12.0
 */
function wp_presence_register_default_scenes() {
	wp_register_presence_scene(
		'presence-api/lock-takeover',
		array(
			'label' => __( 'Lock takeover', 'presence-api' ),
			'cast'  => array( array( 'role' => 'editor' ), array( 'role' => 'editor' ) ),
			'cues'  => array(
				array(
					'after'    => 0,
					'label'    => __( 'Alice and Bob arrive on the dashboard', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[0]->enter( 'dashboard' );
						$cast[1]->enter( 'dashboard' );
					},
				),
				array(
					'after'    => 5,
					'label'    => __( 'Alice opens her draft', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[0]->open( $cast[0]->write( __( 'Quarterly budget', 'presence-api' ) ) );
					},
				),
				array(
					'after'    => 20,
					'label'    => __( 'Bob opens the same draft and finds it locked', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[1]->open( $cast[0]->post() );
						wp_presence_scene_expect( $cast[1]->sees_lock( $cast[0]->post() ) === $cast[0]->ID, __( 'Bob does not see Alice holding the lock.', 'presence-api' ) );
					},
				),
				array(
					'after'    => 35,
					'label'    => __( 'Bob takes over', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[1]->take_over( $cast[0]->post() );
						wp_presence_scene_expect( $cast[0]->sees_lock( $cast[0]->post() ) === $cast[1]->ID, __( 'Alice does not see Bob holding the lock.', 'presence-api' ) );
					},
				),
				array(
					'after'    => 45,
					'label'    => __( 'Bob saves a revision', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[1]->type( $cast[0]->post(), __( 'Moved the travel line into operations.', 'presence-api' ) );
					},
				),
				array(
					'after'    => 60,
					'label'    => __( 'Alice and Bob log out', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[0]->leave();
						$cast[1]->leave();
					},
				),
			),
		)
	);

	$team     = array(
		array(
			'after'    => 0,
			'label'    => __( 'Alice arrives on the dashboard', 'presence-api' ),
			'callback' => function ( $cast ) {
				$cast[0]->enter( 'dashboard' );
			},
		),
	);
	$arrivals = array(
		__( 'Bob arrives on the dashboard', 'presence-api' ),
		__( 'Carol arrives on the dashboard', 'presence-api' ),
		__( 'Dave arrives on the dashboard', 'presence-api' ),
	);
	foreach ( $arrivals as $i => $label ) {
		$team[] = array(
			'after'    => 6 * ( $i + 1 ),
			'label'    => $label,
			'callback' => function ( $cast ) use ( $i ) {
				$cast[ $i + 1 ]->enter( 'dashboard' );
			},
		);
	}
	$titles = array(
		'Alice' => __( 'Launch checklist', 'presence-api' ),
		'Bob'   => __( 'Interview questions', 'presence-api' ),
		'Carol' => __( 'Style guide updates', 'presence-api' ),
		'Dave'  => __( 'Weekly roundup', 'presence-api' ),
	);
	foreach ( array_values( $titles ) as $i => $title ) {
		$team[] = array(
			'after'    => 30 + 6 * $i,
			/* translators: 1: Actor name, 2: Post title. */
			'label'    => sprintf( __( '%1$s opens a draft, "%2$s"', 'presence-api' ), array_keys( $titles )[ $i ], $title ),
			'callback' => function ( $cast ) use ( $i, $title ) {
				$cast[ $i ]->open( $cast[ $i ]->write( $title ) );
			},
		);
	}
	$team[] = array(
		'after'    => 75,
		'label'    => __( 'Everyone logs out', 'presence-api' ),
		'callback' => function ( $cast ) {
			foreach ( $cast as $actor ) {
				$actor->leave();
			}
		},
	);

	wp_register_presence_scene(
		'presence-api/small-team',
		array(
			'label' => __( 'Small team', 'presence-api' ),
			'cast'  => array( array( 'role' => 'author' ), array( 'role' => 'author' ), array( 'role' => 'editor' ), array( 'role' => 'editor' ) ),
			'cues'  => $team,
		)
	);

	$aged_out = 25 + wp_presence_get_timeout() + 5;
	wp_register_presence_scene(
		'presence-api/connection-lost',
		array(
			'label' => __( 'Connection lost', 'presence-api' ),
			'cast'  => array( array( 'role' => 'author' ) ),
			'cues'  => array(
				array(
					'after'    => 0,
					'label'    => __( 'Alice arrives on the dashboard', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[0]->enter( 'dashboard' );
					},
				),
				array(
					'after'    => 5,
					'label'    => __( 'Alice opens a draft', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[0]->open( $cast[0]->write( __( 'Field notes', 'presence-api' ) ) );
					},
				),
				array(
					'after'    => 25,
					'label'    => __( 'Alice loses her connection', 'presence-api' ),
					'callback' => function ( $cast ) {
						$cast[0]->drop();
					},
				),
				array(
					'after'    => $aged_out,
					'label'    => __( 'Alice has aged out', 'presence-api' ),
					'callback' => function ( $cast ) {
						wp_presence_scene_expect( ! $cast[0]->is_present(), __( 'Alice is still listed online after her rows expired.', 'presence-api' ) );
					},
				),
			),
		)
	);
}

/**
 * Strikes every scene and cast member, for plugin deactivation.
 *
 * @since 0.12.0
 */
function wp_presence_scene_sweep_all() {
	wp_presence_scene_sweep( true );
}
