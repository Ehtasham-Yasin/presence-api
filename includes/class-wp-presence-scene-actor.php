<?php
/**
 * Scene actor: one cast member of a debugger scene.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One cast member, acting as their own user through core APIs.
 *
 * @since 0.12.0
 */
final class WP_Presence_Scene_Actor {

	/**
	 * User ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The running scene, shared by the whole cast.
	 *
	 * @var array
	 */
	private $run;

	/**
	 * Constructor.
	 *
	 * @param int   $user_id User ID.
	 * @param array $run     The running scene.
	 */
	public function __construct( $user_id, array &$run ) {
		$user       = get_userdata( $user_id );
		$this->ID   = (int) $user_id;
		$this->name = $user ? $user->display_name : '#' . $user_id;
		$this->run  = &$run;
	}

	/**
	 * Arrives on an admin screen, as the online list would see it.
	 *
	 * @param string $screen  Screen ID, or the post type on the post editor.
	 * @param int    $post_id Optional. The scene post on screen.
	 */
	public function enter( $screen, $post_id = 0 ) {
		$titles = array(
			'dashboard' => __( 'Dashboard', 'presence-api' ),
			'edit-post' => __( 'Posts', 'presence-api' ),
			'post'      => __( 'Edit Post', 'presence-api' ),
		);

		$state = array( 'screen' => $screen );
		if ( $post_id ) {
			$state['post_status'] = $this->scene_post( $post_id )->post_status;
		}
		if ( isset( $titles[ $screen ] ) ) {
			$state['title'] = $titles[ $screen ];
		}
		$state['color'] = wp_presence_assign_user_color( $this->ID );

		$this->beat( wp_presence_admin_room(), 'user-' . $this->ID, $state );
	}

	/**
	 * Writes a draft of their own.
	 *
	 * @param string $title Post title.
	 * @return int The post ID.
	 *
	 * @throws RuntimeException When the post cannot be created.
	 */
	public function write( $title ) {
		$post_id = $this->act(
			function () use ( $title ) {
				return wp_insert_post(
					array(
						'post_title'   => $title,
						'post_content' => "<!-- wp:paragraph -->\n<p>" . esc_html( $title ) . "</p>\n<!-- /wp:paragraph -->",
						'post_status'  => 'draft',
						'post_author'  => $this->ID,
					),
					true
				);
			}
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		$this->run['posts'][] = (int) $post_id;

		return (int) $post_id;
	}

	/**
	 * Opens a scene post in the editor, taking the lock when nobody holds it.
	 *
	 * @param int $post_id Scene post ID.
	 */
	public function open( $post_id ) {
		$post = $this->scene_post( $post_id );
		$this->enter( $post->post_type, $post->ID );

		$locked = ! $this->sees_lock( $post->ID );
		if ( $locked ) {
			$this->lock( $post->ID );
		}

		$this->beat( wp_presence_post_room( $post ), 'editor-' . $this->ID, wp_presence_editor_state( $post->post_type, $locked ), $locked ? $post->ID : 0 );
	}

	/**
	 * Takes over a scene post someone else has locked.
	 *
	 * @param int $post_id Scene post ID.
	 */
	public function take_over( $post_id ) {
		$post = $this->scene_post( $post_id );
		$this->lock( $post->ID );

		foreach ( $this->run['beats'] as $user_id => $beats ) {
			foreach ( $beats as $key => $beat ) {
				if ( $user_id !== $this->ID && $beat[3] === $post->ID ) {
					$this->run['beats'][ $user_id ][ $key ][2]['locked'] = false;
					$this->run['beats'][ $user_id ][ $key ][3]           = 0;
				}
			}
		}

		$this->enter( $post->post_type, $post->ID );
		$this->beat( wp_presence_post_room( $post ), 'editor-' . $this->ID, wp_presence_editor_state( $post->post_type, true ), $post->ID );
	}

	/**
	 * Adds a paragraph to a scene post and saves it.
	 *
	 * @param int    $post_id Scene post ID.
	 * @param string $text    Paragraph text.
	 *
	 * @throws RuntimeException When the update fails.
	 */
	public function type( $post_id, $text ) {
		$post   = $this->scene_post( $post_id );
		$result = $this->act(
			function () use ( $post, $text ) {
				return wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $post->post_content . "\n\n<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->",
					),
					true
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Leaves the editor for the posts list, releasing their lock.
	 *
	 * @param int $post_id Scene post ID.
	 */
	public function close( $post_id ) {
		$post = $this->scene_post( $post_id );
		$room = wp_presence_post_room( $post );

		unset( $this->run['beats'][ $this->ID ][ $room . ' editor-' . $this->ID ] );
		wp_remove_presence( $room, 'editor-' . $this->ID );

		$lock = explode( ':', (string) get_post_meta( $post->ID, '_edit_lock', true ) );
		if ( isset( $lock[1] ) && (int) $lock[1] === $this->ID ) {
			delete_post_meta( $post->ID, '_edit_lock' );
		}

		$this->enter( 'edit-' . $post->post_type );
	}

	/**
	 * Stops beating without saying goodbye, like a closed laptop.
	 */
	public function drop() {
		unset( $this->run['beats'][ $this->ID ] );
	}

	/**
	 * Logs out.
	 */
	public function leave() {
		unset( $this->run['beats'][ $this->ID ] );
		wp_remove_user_presence( $this->ID );
	}

	/**
	 * The latest scene post they wrote.
	 *
	 * @return int The post ID.
	 *
	 * @throws RuntimeException When they have not written one.
	 */
	public function post() {
		foreach ( array_reverse( $this->run['posts'] ) as $post_id ) {
			if ( (int) get_post_field( 'post_author', $post_id ) === $this->ID ) {
				return (int) $post_id;
			}
		}

		/* translators: %s: Actor name. */
		throw new RuntimeException( sprintf( __( '%s has not written a post yet.', 'presence-api' ), $this->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
	}

	/**
	 * Whether the online list shows them.
	 *
	 * @return bool
	 */
	public function is_present() {
		foreach ( wp_get_presence( wp_presence_admin_room() ) as $entry ) {
			if ( (int) $entry->user_id === $this->ID ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Who they see holding a post's lock.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false The other user's ID, or false when nobody else holds it.
	 */
	public function sees_lock( $post_id ) {
		return $this->act(
			function () use ( $post_id ) {
				return wp_check_post_lock( $post_id );
			}
		);
	}

	/**
	 * Re-stamps every row they hold, as their own Heartbeat would.
	 *
	 * @return array[] Notes, each a level and a message.
	 */
	public function beat_again() {
		$errors = array();

		foreach ( $this->run['beats'][ $this->ID ] ?? array() as $key => $beat ) {
			list( $room, $client_id, $state, $lock ) = $beat;

			if ( $lock ) {
				$holder = $this->sees_lock( $lock );
				if ( $holder ) {
					$state['locked'] = false;
					$lock            = 0;

					$this->run['beats'][ $this->ID ][ $key ] = array( $room, $client_id, $state, $lock );

					$user = get_userdata( $holder );
					/* translators: 1: Actor name, 2: Post ID, 3: The user who took it over. */
					$errors[] = array( 'info', sprintf( __( '%1$s lost the lock on post %2$d to %3$s.', 'presence-api' ), $this->name, $beat[3], $user ? $user->display_name : '#' . $holder ) );
				} else {
					$this->lock( $lock );
				}
			}

			if ( ! wp_set_presence( $room, $client_id, $state, $this->ID ) ) {
				/* translators: 1: Client ID, 2: Room. */
				$errors[] = array( 'fail', sprintf( __( 'wp_set_presence() refused %1$s in %2$s.', 'presence-api' ), $client_id, $room ) );
			}
		}

		return $errors;
	}

	/**
	 * Writes a row and keeps it beating.
	 *
	 * @param string $room      Room.
	 * @param string $client_id Client ID.
	 * @param array  $state     State.
	 * @param int    $lock      Optional. The post whose lock this row refreshes.
	 *
	 * @throws RuntimeException When the write is refused.
	 */
	private function beat( $room, $client_id, $state, $lock = 0 ) {
		if ( ! $room || ! wp_set_presence( $room, $client_id, $state, $this->ID ) ) {
			/* translators: 1: Client ID, 2: Room. */
			throw new RuntimeException( sprintf( __( 'wp_set_presence() refused %1$s in %2$s.', 'presence-api' ), $client_id, $room ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		$this->run['beats'][ $this->ID ][ $room . ' ' . $client_id ] = array( $room, $client_id, $state, (int) $lock );
	}

	/**
	 * Takes a post's lock as this actor.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @throws RuntimeException When core refuses the lock.
	 */
	private function lock( $post_id ) {
		$lock = $this->act(
			function () use ( $post_id ) {
				return wp_set_post_lock( $post_id );
			}
		);

		if ( ! $lock ) {
			/* translators: %d: Post ID. */
			throw new RuntimeException( sprintf( __( 'wp_set_post_lock() refused post %d.', 'presence-api' ), $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Returns a post the scene created, refusing anything else.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post The post.
	 *
	 * @throws RuntimeException When the post is not the scene's.
	 */
	private function scene_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( (int) $post_id, $this->run['posts'], true ) ) {
			/* translators: %d: Post ID. */
			throw new RuntimeException( sprintf( __( 'Post %d is not part of the scene.', 'presence-api' ), $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		return $post;
	}

	/**
	 * Runs a callback as this actor.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	private function act( $callback ) {
		$previous = get_current_user_id();
		wp_set_current_user( $this->ID );

		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}
}
