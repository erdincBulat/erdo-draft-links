<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Loader {

	private array $actions = array();
	private array $filters = array();

	public function add_action( string $hook, $component, string $callback, int $priority = 10, int $args = 1 ): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
	}

	public function add_filter( string $hook, $component, string $callback, int $priority = 10, int $args = 1 ): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
	}

	public function run(): void {
		foreach ( $this->filters as $f ) {
			add_filter( $f['hook'], array( $f['component'], $f['callback'] ), $f['priority'], $f['args'] );
		}
		foreach ( $this->actions as $a ) {
			add_action( $a['hook'], array( $a['component'], $a['callback'] ), $a['priority'], $a['args'] );
		}
	}
}
