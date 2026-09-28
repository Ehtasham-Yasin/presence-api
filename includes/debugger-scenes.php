<?php
/**
 * Scenes: registered scenes create marked users, play their cues on the debugging tab's Heartbeat, then delete them.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Places an actor can visit, each with its screen ID, title and the capability it needs.
 *
 * @since 0.12.0
 *
 * @return array[] Places keyed by the name a scene uses.
 */
function wp_presence_scene_places() {
	$places = array(
		'dashboard' => array( 'dashboard', __( 'Dashboard', 'presence-api' ), 'read' ),
		'posts'     => array( 'edit-post', __( 'Posts', 'presence-api' ), 'edit_posts' ),
		'pages'     => array( 'edit-page', __( 'Pages', 'presence-api' ), 'edit_pages' ),
		'media'     => array( 'upload', __( 'Media', 'presence-api' ), 'upload_files' ),
		'comments'  => array( 'edit-comments', __( 'Comments', 'presence-api' ), 'edit_posts' ),
		'profile'   => array( 'profile', __( 'Profile', 'presence-api' ), 'read' ),
	);

	/**
	 * Filters the places a scene can send an actor, so plugins can add their own screens.
	 *
	 * @since 0.12.0
	 *
	 * @param array[] $places Each a screen ID, a title and the capability it needs, keyed by the name scenes use.
	 */
	$places = apply_filters( 'wp_presence_scene_places', $places );

	return array_filter(
		is_array( $places ) ? $places : array(),
		function ( $place, $name ) {
			return is_string( $name ) && preg_match( '/^[a-z0-9-]+$/', $name ) && is_array( $place ) && 3 === count( $place ) && array_filter( $place, 'is_string' ) === $place;
		},
		ARRAY_FILTER_USE_BOTH
	);
}

/**
 * Registers a scene for the debugger to direct.
 *
 * A scene is data only: every cue is an action from a fixed list, acted
 * through WP_Presence_Scene_Actor on posts the scene writes itself.
 *
 * @since 0.12.0
 *
 * @param string|array $scene Path to a scene.json file, or the same shape as an array. See schemas/scene.json.
 * @return bool Whether the scene was registered.
 */
function wp_register_presence_scene( $scene ) {
	global $wp_presence_scenes;

	if ( ! doing_action( 'wp_presence_scenes_init' ) ) {
		/* translators: %s: Action name. */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'Scenes must be registered on the %s action.', 'presence-api' ), '<code>wp_presence_scenes_init</code>' ), '0.12.0' );
		return false;
	}

	$source = is_string( $scene ) ? wp_basename( $scene ) : '';
	if ( is_string( $scene ) ) {
		$scene = '.json' === substr( $scene, -5 ) && is_file( $scene ) && filesize( $scene ) <= 65536
			? wp_json_file_decode( $scene, array( 'associative' => true ) )
			: null;
	}

	$prepared = wp_presence_scene_prepare( $scene );

	if ( is_wp_error( $prepared ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html( ( $source ? $source . ': ' : '' ) . $prepared->get_error_message() ), '0.12.0' );
		return false;
	}

	if ( isset( $wp_presence_scenes[ $prepared['name'] ] ) ) {
		/* translators: %s: Scene name. */
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Scene "%s" is already registered.', 'presence-api' ), $prepared['name'] ) ), '0.12.0' );
		return false;
	}

	$wp_presence_scenes[ $prepared['name'] ] = $prepared;

	return true;
}

/**
 * Actions a cue can take, each with the fields it needs and how its step is narrated.
 *
 * The action name maps to the WP_Presence_Scene_Actor method that plays it, so takeOver plays take_over().
 *
 * @since 0.12.0
 *
 * @return array[] Actions keyed by name.
 */
function wp_presence_scene_actions() {
	return array(
		'visit'         => array(
			'fields' => array( 'place' ),
			'cast'   => true,
			/* translators: 1: Actor, 2: Screen title. */
			'label'  => __( '%1$s arrives on %2$s', 'presence-api' ),
			/* translators: 1: Actor, 2: Screen title. */
			'again'  => __( '%1$s goes to %2$s', 'presence-api' ),
		),
		'write'         => array(
			'fields' => array( 'title' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s writes "%2$s"', 'presence-api' ),
		),
		'open'          => array(
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s opens "%2$s"', 'presence-api' ),
		),
		'takeOver'      => array(
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s takes over "%2$s"', 'presence-api' ),
		),
		'type'          => array(
			'fields' => array( 'post', 'text' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s edits "%2$s"', 'presence-api' ),
		),
		'close'         => array(
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s closes "%2$s"', 'presence-api' ),
		),
		'drop'          => array(
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s loses connection', 'presence-api' ),
		),
		'leave'         => array(
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s logs out', 'presence-api' ),
		),
		'checkOnline'   => array(
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s is online', 'presence-api' ),
		),
		'checkOffline'  => array(
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s is offline', 'presence-api' ),
		),
		'checkLocked'   => array(
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s finds "%2$s" locked', 'presence-api' ),
		),
		'checkUnlocked' => array(
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s finds "%2$s" free', 'presence-api' ),
		),
	);
}

/**
 * Checks a scene against the allowed actions and narrates each cue.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param mixed $scene The decoded scene.
 * @return array|WP_Error The scene ready to direct, or why it cannot be.
 */
function wp_presence_scene_prepare( $scene ) {
	$actions = wp_presence_scene_actions();
	$places  = wp_presence_scene_places();
	$roles   = array( 'subscriber', 'contributor', 'author', 'editor' );
	$invalid = function ( $message, $n = null ) {
		/* translators: 1: Cue number, 2: What is wrong with it. */
		return new WP_Error( 'presence_scene_invalid', null === $n ? $message : sprintf( __( 'Cue %1$d: %2$s', 'presence-api' ), $n + 1, $message ) );
	};

	if ( ! is_array( $scene ) || array_diff( array_keys( $scene ), array( '$schema', 'apiVersion', 'name', 'title', 'cast', 'cues' ) ) ) {
		return $invalid( __( 'A scene has only $schema, apiVersion, name, title, cast and cues.', 'presence-api' ) );
	}

	if ( 1 !== ( $scene['apiVersion'] ?? 1 ) ) {
		return $invalid( __( 'This version of the plugin reads apiVersion 1 only.', 'presence-api' ) );
	}

	if ( ! is_string( $scene['name'] ?? null ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $scene['name'] ) ) {
		return $invalid( __( 'The name must be a namespace and a name, lowercase with dashes, such as my-plugin/review-queue.', 'presence-api' ) );
	}

	$title = wp_presence_scene_text( $scene['title'] ?? null, 60 );
	if ( null === $title ) {
		return $invalid( __( 'The title must be text of up to 60 characters.', 'presence-api' ) );
	}

	$cast = $scene['cast'] ?? null;
	if ( ! is_array( $cast ) || ! wp_is_numeric_array( $cast ) || count( $cast ) < 1 || count( $cast ) > 5 ) {
		return $invalid( __( 'The cast must list one to five actors.', 'presence-api' ) );
	}
	foreach ( $cast as $part ) {
		if ( ! is_array( $part ) || array_keys( $part ) !== array( 'role' ) || ! in_array( $part['role'], $roles, true ) ) {
			/* translators: %s: Allowed roles. */
			return $invalid( sprintf( __( 'Each actor needs only a role, one of %s.', 'presence-api' ), implode( ', ', $roles ) ) );
		}
	}

	$cues = $scene['cues'] ?? null;
	if ( ! is_array( $cues ) || ! wp_is_numeric_array( $cues ) || count( $cues ) < 1 || count( $cues ) > 30 ) {
		return $invalid( __( 'The cues must list one to thirty actions.', 'presence-api' ) );
	}

	$prepared = array();
	$titles   = array();
	$present  = array_fill( 0, count( $cast ), false );
	$last     = 0;

	foreach ( $cues as $n => $cue ) {
		$action = is_array( $cue ) && is_string( $cue['action'] ?? null ) ? $cue['action'] : '';

		if ( ! isset( $actions[ $action ] ) ) {
			/* translators: %s: Allowed actions. */
			return $invalid( sprintf( __( 'The action must be one of %s.', 'presence-api' ), implode( ', ', array_keys( $actions ) ) ), $n );
		}

		$fields  = $actions[ $action ]['fields'];
		$allowed = array_merge( array( 'at', 'actor', 'action' ), $fields );
		if ( array_diff( array_keys( $cue ), $allowed ) || array_diff( array_diff( $allowed, array( 'text' ) ), array_keys( $cue ) ) ) {
			/* translators: 1: Action, 2: Its fields. */
			return $invalid( sprintf( __( '%1$s takes %2$s, and text is optional.', 'presence-api' ), $action, implode( ', ', $allowed ) ), $n );
		}

		$at = $cue['at'];
		if ( is_string( $at ) && preg_match( '/^(?:(\d{1,3})\+)?ttl(?:\+(\d{1,3}))?$/', $at, $m ) ) {
			$at = (int) ( $m[1] ?? 0 ) + wp_presence_get_timeout() + (int) ( $m[2] ?? 0 );
		}
		if ( ! is_int( $at ) || $at < $last || $at > 900 ) {
			return $invalid( __( 'at must be seconds from 0 to 900, or a TTL offset such as "25+ttl+5", and never earlier than the cue before.', 'presence-api' ), $n );
		}
		$last = $at;

		$actor = $cue['actor'];
		$whole = 'cast' === $actor && $actions[ $action ]['cast'];
		if ( ! $whole && ! ( is_int( $actor ) && $actor >= 1 && $actor <= count( $cast ) ) ) {
			/* translators: %d: Number of actors. */
			return $invalid( sprintf( __( 'actor must be a number from 1 to %d, or "cast" for actions the whole cast can take.', 'presence-api' ), count( $cast ) ), $n );
		}

		$clean  = array(
			'after'  => $at,
			'actor'  => $actor,
			'action' => $action,
		);
		$object = '';

		if ( isset( $cue['place'] ) ) {
			if ( ! is_string( $cue['place'] ) || ! isset( $places[ $cue['place'] ] ) ) {
				/* translators: %s: Allowed places. */
				return $invalid( sprintf( __( 'place must be one of %s.', 'presence-api' ), implode( ', ', array_keys( $places ) ) ), $n );
			}
			$clean['place'] = $cue['place'];
			$object         = $places[ $cue['place'] ][1];
		}

		if ( isset( $cue['post'] ) ) {
			if ( ! is_int( $cue['post'] ) || $cue['post'] < 1 || $cue['post'] > count( $titles ) ) {
				return $invalid( __( 'post must number a post an earlier write cue created, starting at 1.', 'presence-api' ), $n );
			}
			$clean['post'] = $cue['post'];
			$object        = $titles[ $cue['post'] - 1 ];
		}

		foreach ( array( 'title', 'text' ) as $key ) {
			if ( ! isset( $cue[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = wp_presence_scene_text( $cue[ $key ], 100 );
			if ( null === $clean[ $key ] ) {
				/* translators: %s: Field name. */
				return $invalid( sprintf( __( '%s must be text of up to 100 characters.', 'presence-api' ), $key ), $n );
			}
		}

		if ( 'write' === $action ) {
			$titles[] = $clean['title'];
			$object   = $clean['title'];
		}

		$members = $whole ? array_keys( $present ) : array( $actor - 1 );
		$format  = 'visit' === $action && $present[ $members[0] ] ? $actions[ $action ]['again'] : $actions[ $action ]['label'];

		$clean['label'] = sprintf( $format, $whole ? __( 'The cast', 'presence-api' ) : wp_presence_scene_actor_name( $actor - 1 ), $object );
		$prepared[]     = $clean;

		if ( 0 !== strpos( $action, 'check' ) ) {
			foreach ( $members as $i ) {
				$present[ $i ] = ! in_array( $action, array( 'drop', 'leave' ), true );
			}
		}
	}

	return array(
		'name'     => $scene['name'],
		'label'    => $title,
		'cast'     => $cast,
		'cues'     => $prepared,
		'duration' => $last,
	);
}

/**
 * Sanitizes a scene string, refusing anything that is not short plain text.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param mixed $value The value from the scene.
 * @param int   $max   Maximum length in characters.
 * @return string|null The text, or null when it is not allowed.
 */
function wp_presence_scene_text( $value, $max ) {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$text = sanitize_text_field( $value );

	return '' !== $text && $text === $value && mb_strlen( $text ) <= $max ? $text : null;
}

/**
 * Plays one cue through the actors it names.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array                     $cue  A prepared cue.
 * @param WP_Presence_Scene_Actor[] $cast The cast.
 * @param array                     $run  The running scene.
 */
function wp_presence_scene_perform( array $cue, array $cast, array $run ) {
	$method = strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $cue['action'] ) );
	$args   = array();

	foreach ( wp_presence_scene_actions()[ $cue['action'] ]['fields'] as $field ) {
		$args[] = 'post' === $field ? (int) ( $run['posts'][ $cue['post'] - 1 ] ?? 0 ) : ( $cue[ $field ] ?? null );
	}

	foreach ( 'cast' === $cue['actor'] ? $cast : array( $cast[ $cue['actor'] - 1 ] ) as $actor ) {
		$actor->$method( ...$args );
	}
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

	foreach ( array_values( $scene['cast'] ) as $i => $part ) {
		$login   = 'actor' . ( $i + 1 ) . 'run' . $number;
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.com',
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => wp_presence_scene_actor_name( $i ),
				'first_name'   => wp_presence_scene_actor_name( $i ),
				'role'         => $part['role'],
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
		$run['done'][ $i ] = 'pass';

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collects the cue's warnings for the report.
		set_error_handler(
			function ( $errno, $errstr, $file, $line ) use ( &$warnings ) {
				$warnings[] = sprintf( '%s (%s:%d)', $errstr, wp_basename( $file ), $line );
				return true;
			}
		);

		try {
			wp_presence_scene_perform( $cue, $cast, $run );
			wp_presence_scene_note( $run, 'pass', $cue['label'] );
		} catch ( Throwable $e ) {
			$run['done'][ $i ] = 'fail';
			wp_presence_scene_note( $run, 'fail', $cue['label'] . ': ' . $e->getMessage() );
		} finally {
			restore_error_handler();
		}

		if ( $warnings ) {
			$run['done'][ $i ] = 'fail';
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

	if ( count( $run['done'] ) === count( $scene['cues'] ) ) {
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

	$before = count( $run['notes'] );

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
			wp_presence_scene_note( $run, 'fail', sprintf( __( 'User %d is still there after cleanup.', 'presence-api' ), $user_id ) );
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
			wp_presence_scene_note( $run, 'fail', sprintf( __( 'Post %d is still there after cleanup.', 'presence-api' ), $post_id ) );
		}
	}

	$cleaned  = count( $run['notes'] ) === $before;
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
			? sprintf( _n( 'Finished with %s problem.', 'Finished with %s problems.', $problems, 'presence-api' ), number_format_i18n( $problems ) )
			: __( 'Finished with no problems.', 'presence-api' )
	);

	delete_option( 'wp_presence_scene' );
	update_option(
		'wp_presence_scene_notes',
		array(
			'run'     => $run['run'],
			'name'    => $run['name'],
			'label'   => $run['label'],
			'done'    => $run['done'],
			'cleaned' => $cleaned,
			'notes'   => $run['notes'],
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
		wp_presence_scene_note( $run, 'info', __( 'Cleaned up before the scene finished.', 'presence-api' ) );
		wp_presence_scene_strike( $run );
	}

	$query = array(
		'blog_id'  => 0,
		'meta_key' => '_wp_presence_scene', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);
	if ( ! $all ) {
		$query['meta_value']   = time(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query['meta_compare'] = '<';
		$query['meta_type']    = 'NUMERIC';
	}

	$query['fields'] = array( 'ID', 'user_login' );
	$user_ids        = array();
	foreach ( get_users( $query ) as $user ) {
		if ( preg_match( '/^actor[1-5]run\d+$/', $user->user_login ) ) {
			$user_ids[] = (int) $user->ID;
		}
	}
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
 * Whether the current user may create and delete the users a scene casts.
 *
 * @since 0.12.0
 *
 * @return bool Whether the current user can direct scenes.
 */
function wp_presence_scene_user_can_direct() {
	return current_user_can( 'manage_options' ) && current_user_can( 'create_users' ) && current_user_can( 'delete_users' );
}

/**
 * Refuses to log in as an actor.
 *
 * @since 0.12.0
 *
 * @param WP_User|WP_Error $user The user logging in.
 * @return WP_User|WP_Error The user, or an error for an actor.
 */
function wp_presence_scene_authenticate( $user ) {
	if ( $user instanceof WP_User && get_user_meta( $user->ID, '_wp_presence_scene', true ) ) {
		return new WP_Error( 'presence_scene_actor', __( 'Scene actors cannot log in.', 'presence-api' ) );
	}

	return $user;
}

/**
 * Handles the debugger's start, cut and clear links.
 *
 * @since 0.12.0
 */
function wp_presence_scene_admin_post() {
	if ( ! wp_presence_scene_user_can_direct() ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to direct scenes.', 'presence-api' ), 403 );
	}

	check_admin_referer( 'wp_presence_scene' );

	$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

	if ( 'start' === $do && isset( $_GET['scene'] ) ) {
		wp_presence_scene_start( sanitize_text_field( wp_unslash( $_GET['scene'] ) ) );
	} elseif ( 'cut' === $do ) {
		$run = get_option( 'wp_presence_scene' );
		if ( is_array( $run ) ) {
			wp_presence_scene_note( $run, 'info', __( 'Stopped.', 'presence-api' ) );
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
	if ( empty( $data['presence-scene'] ) || ! wp_presence_scene_user_can_direct() ) {
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
 * Names the actor cast in a scene's part, such as "Actor 1".
 *
 * @since 0.12.0
 *
 * @param int $i Zero-based index of the part.
 * @return string
 */
function wp_presence_scene_actor_name( $i ) {
	/* translators: %d: Actor number. */
	return sprintf( __( 'Actor %d', 'presence-api' ), $i + 1 );
}

/**
 * Lists the users a scene creates, such as "Cast: Actor 1 and Actor 2 as Editor".
 *
 * @since 0.12.0
 *
 * @param array $scene A registered scene.
 * @return string
 */
function wp_presence_scene_casting( array $scene ) {
	$role_names = wp_roles()->role_names;
	$roles      = array();
	foreach ( array_values( $scene['cast'] ) as $i => $part ) {
		$roles[ $part['role'] ][] = wp_presence_scene_actor_name( $i );
	}

	$parts = array();
	foreach ( $roles as $role => $people ) {
		/* translators: 1: Actors, 2: Role. */
		$parts[] = sprintf( __( '%1$s as %2$s', 'presence-api' ), wp_sprintf( '%l', $people ), translate_user_role( $role_names[ $role ] ?? $role ) );
	}

	/* translators: %s: Actors and their roles. */
	return sprintf( __( 'Cast: %s', 'presence-api' ), implode( ', ', $parts ) );
}

/**
 * Narrates the cleanup after a scene, such as "The cast exits".
 *
 * @since 0.12.0
 *
 * @param array $scene A registered scene.
 * @return string
 */
function wp_presence_scene_exit( array $scene ) {
	return 1 === count( $scene['cast'] )
		/* translators: %s: Actor name. */
		? sprintf( __( '%s exits', 'presence-api' ), wp_presence_scene_actor_name( 0 ) )
		: __( 'The cast exits', 'presence-api' );
}

/**
 * Adds a Scenes section to the debugger menu, listing every step a scene will take before it can run.
 *
 * @since 0.12.0
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_scene_admin_bar_nodes( $wp_admin_bar ) {
	$scenes = wp_presence_scene_user_can_direct() ? wp_get_presence_scenes() : array();

	if ( ! $scenes ) {
		return;
	}

	$run    = get_option( 'wp_presence_scene' );
	$report = is_array( $run ) ? null : get_option( 'wp_presence_scene_notes' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared against scene slugs.
	$open  = isset( $_COOKIE['wp_presence_scene_open'] ) ? wp_unslash( $_COOKIE['wp_presence_scene_open'] ) : '';
	$marks = array(
		'pending' => __( 'Not played yet:', 'presence-api' ),
		'pass'    => __( 'Passed:', 'presence-api' ),
		'fail'    => __( 'Failed:', 'presence-api' ),
	);

	$wp_admin_bar->add_group(
		array(
			'parent' => 'presence-debug',
			'id'     => 'presence-debug-scenes',
			'meta'   => array( 'class' => 'ab-sub-secondary' ),
		)
	);
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-debug-scenes',
			'id'     => 'presence-debug-scenes-heading',
			'title'  => esc_html__( 'Scenes', 'presence-api' ),
			'meta'   => array( 'class' => 'presence-debug-scenes-heading' ),
		)
	);

	foreach ( $scenes as $name => $scene ) {
		$slug     = str_replace( '/', '-', $name );
		$running  = is_array( $run ) && $run['name'] === $name;
		$result   = $running ? $run : ( is_array( $report ) && ( $report['name'] ?? '' ) === $name ? $report : null );
		$shown    = $running || $open === $slug;
		$value    = '';
		$problems = array();

		if ( $running ) {
			/* translators: 1: Steps played, 2: Total steps. */
			$value = sprintf( __( '%1$s / %2$s', 'presence-api' ), number_format_i18n( count( $run['done'] ) + 1 ), number_format_i18n( count( $scene['cues'] ) + 2 ) );
		} elseif ( $result ) {
			// The last note is the summary, which only counts the others.
			$problems = array_filter(
				array_slice( $result['notes'], 0, -1 ),
				function ( $note ) {
					return 'fail' === $note['level'] || 'warning' === $note['level'];
				}
			);
			$value    = $problems
				/* translators: %s: Number of problems. */
				? sprintf( _n( 'Done, %s problem', 'Done, %s problems', count( $problems ), 'presence-api' ), number_format_i18n( count( $problems ) ) )
				: __( 'Done', 'presence-api' );
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug-scenes',
				'id'     => 'presence-debug-scene-' . $slug,
				'title'  => '<span class="presence-debug-scene-icon" aria-hidden="true"></span><span>' . esc_html( $scene['label'] ) . '</span>'
					. ( '' !== $value ? '<span class="presence-debug-value">' . esc_html( $value ) . '</span>' : '' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'presence-debug-scene' . ( $shown ? ' is-open' : '' ) ),
			)
		);

		$steps = array(
			array(
				'label' => wp_presence_scene_casting( $scene ),
				'after' => null,
				'state' => $result ? 'pass' : 'pending',
			),
		);
		foreach ( $scene['cues'] as $i => $cue ) {
			$steps[] = array(
				'label' => $cue['label'],
				'after' => (int) $cue['after'],
				'state' => isset( $result['done'][ $i ] ) ? ( 'fail' === $result['done'][ $i ] ? 'fail' : 'pass' ) : 'pending',
			);
		}
		$steps[] = array(
			'label' => wp_presence_scene_exit( $scene ),
			'after' => $scene['duration'],
			'state' => isset( $result['cleaned'] ) && ! $running ? ( $result['cleaned'] ? 'pass' : 'fail' ) : 'pending',
		);

		$hidden = 'presence-debug-for-' . $slug . ( $shown ? '' : ' is-hidden' );

		foreach ( $steps as $i => $step ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-scenes',
					'id'     => 'presence-debug-scene-' . $slug . '-step-' . $i,
					'title'  => '<span class="presence-debug-step-mark" aria-hidden="true"></span><span><span class="screen-reader-text">' . esc_html( $marks[ $step['state'] ] ) . ' </span>' . esc_html( $step['label'] ) . '</span>'
						. ( null !== $step['after'] ? '<span class="presence-debug-value">' . esc_html( $step['after'] . 's' ) . '</span>' : '' ),
					'meta'   => array( 'class' => 'presence-debug-step is-' . $step['state'] . ' ' . $hidden ),
				)
			);
		}

		foreach ( $problems as $i => $note ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-scenes',
					'id'     => 'presence-debug-scene-' . $slug . '-note-' . $i,
					'title'  => '<span>' . esc_html( $note['message'] ) . '</span><span class="presence-debug-value" data-presence-debug="note" data-presence-debug-seconds="' . esc_attr( $note['t'] ) . '"></span>',
					'meta'   => array( 'class' => 'presence-debug-note ' . $hidden ),
				)
			);
		}

		/* translators: %s: Scene label. */
		$plan = sprintf( __( 'Run "%s"?', 'presence-api' ), $scene['label'] ) . "\n\n"
			/* translators: 1: Who is cast, 2: Duration in seconds, 3: Who exits. */
			. sprintf( __( '%1$s. After %2$ss: %3$s.', 'presence-api' ), wp_presence_scene_casting( $scene ), $scene['duration'], wp_presence_scene_exit( $scene ) );

		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug-scenes',
				'id'     => 'presence-debug-scene-' . $slug . '-action',
				'title'  => '<span class="presence-debug-scene-icon" aria-hidden="true"></span><span>' . esc_html( $running ? __( 'Stop scene', 'presence-api' ) : ( $result ? __( 'Run again', 'presence-api' ) : __( 'Run scene', 'presence-api' ) ) ) . '</span>'
					. ( $running ? '' : '<span class="presence-debug-value">'
						/* translators: 1: Number of actors, 2: Duration in seconds. */
						. esc_html( sprintf( _n( '%1$s actor, %2$ss', '%1$s actors, %2$ss', count( $scene['cast'] ), 'presence-api' ), number_format_i18n( count( $scene['cast'] ) ), $scene['duration'] ) )
						. '</span><span class="presence-debug-scene-plan" hidden>' . esc_html( $plan ) . '</span>' ),
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
				'meta'   => array( 'class' => 'presence-debug-scene-' . ( $running ? 'cut' : 'start' ) . ' ' . $hidden ),
			)
		);

		if ( $result && ! $running ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-scenes',
					'id'     => 'presence-debug-scene-' . $slug . '-clear',
					'title'  => '<span class="presence-debug-scene-icon" aria-hidden="true"></span><span>' . esc_html__( 'Clear result', 'presence-api' ) . '</span>',
					'href'   => wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'presence_scene',
								'do'     => 'clear',
							),
							admin_url( 'admin-post.php' )
						),
						'wp_presence_scene'
					),
					'meta'   => array( 'class' => 'presence-debug-scene-clear ' . $hidden ),
				)
			);
		}
	}
}

/**
 * Adds the scene styles, controls and console report to the debugger's assets.
 *
 * @since 0.12.0
 */
function wp_presence_scene_assets() {
	if ( ! wp_script_is( 'presence-debugger-admin-bar' ) ) {
		return;
	}

	wp_add_inline_style(
		'presence-debugger-admin-bar',
		'#wpadminbar #wp-admin-bar-presence-debug-scenes { padding-block: 6px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-hidden { display: none; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading > .ab-item { font-weight: 600; cursor: default; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-icon::before, #wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step-mark::before { display: block; width: 16px; margin-inline-end: 8px; font: 16px/1 dashicons; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene .presence-debug-scene-icon::before { content: "\\f345"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene.is-open .presence-debug-scene-icon::before { content: "\\f347"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-start .presence-debug-scene-icon::before { content: "\\f522"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-clear .presence-debug-scene-icon::before { content: "\\f335"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-cut .presence-debug-scene-icon::before { content: ""; width: 10px; height: 10px; margin-inline: 3px 11px; background: currentColor; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes :is(.presence-debug-step, .presence-debug-scene-start, .presence-debug-scene-cut, .presence-debug-scene-clear) > .ab-item { padding-inline-start: 34px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step > .ab-item { min-height: 22px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step > .ab-item, #wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step > .ab-item * { white-space: nowrap; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step-mark::before { content: ""; box-sizing: border-box; width: 10px; height: 10px; margin-inline: 3px 11px; border: 1.5px solid currentColor; border-radius: 50%; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes :is(.is-pass, .is-fail) .presence-debug-step-mark::before { width: 16px; height: auto; margin-inline: 0 8px; border: 0; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-pass .presence-debug-step-mark::before { content: "\\f147"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-fail .presence-debug-step-mark::before { content: "\\f158"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step .presence-debug-value { padding-inline-start: 16px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-note > .ab-item { max-width: 420px; padding-inline-start: 58px; white-space: normal; }'
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
	const prefix = "wp-admin-bar-presence-debug-scene-";

	// A cookie, so the server renders the open scene on the next beat's swap.
	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene > a", function ( event ) {
		event.preventDefault();
		const scene = this.parentNode;
		const slug = scene.classList.contains( "is-open" ) ? "" : scene.id.slice( prefix.length );
		document.cookie = "wp_presence_scene_open=" + encodeURIComponent( slug ) + "; path=/; SameSite=Lax";
		document.querySelectorAll( "#wp-admin-bar-presence-debug-scenes .presence-debug-scene" ).forEach( function ( other ) {
			const open = other.id.slice( prefix.length ) === slug || !! other.parentNode.querySelector( ".presence-debug-for-" + other.id.slice( prefix.length ) + ".presence-debug-scene-cut" );
			other.classList.toggle( "is-open", open );
			other.parentNode.querySelectorAll( ".presence-debug-for-" + other.id.slice( prefix.length ) ).forEach( function ( row ) {
				row.classList.toggle( "is-hidden", ! open );
			} );
		} );
	} );

	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene-start > a", function ( event ) {
		const plan = this.querySelector( ".presence-debug-scene-plan" );
		if ( ! window.confirm( plan.textContent ) ) {
			event.preventDefault();
		}
	} );

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

	if ( document.querySelector( "#wp-admin-bar-presence-debug-scenes .presence-debug-scene-cut" ) ) {
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
	foreach ( glob( dirname( __DIR__ ) . '/scenes/*.json' ) as $file ) {
		wp_register_presence_scene( $file );
	}
}

/**
 * Strikes every scene and cast member, for plugin deactivation.
 *
 * @since 0.12.0
 */
function wp_presence_scene_sweep_all() {
	wp_presence_scene_sweep( true );
}
