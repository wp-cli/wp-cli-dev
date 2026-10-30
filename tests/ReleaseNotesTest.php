<?php

use WP_CLI\Maintenance\Release_Notes;
use WP_CLI\Tests\TestCase;

class ReleaseNotesTest extends TestCase {

	/**
	 * @return array[]
	 */
	private function sections() {
		return [
			[
				'repo'       => 'wp-cli/wp-cli',
				'milestones' => [
					[
						'milestone'     => '3.0.0',
						'source'        => 'release',
						'body'          => '- Release body',
						'pull_requests' => [],
					],
				],
			],
			[
				'repo'       => 'wp-cli/entity-command',
				'milestones' => [
					[
						'milestone'     => '3.0.2',
						'source'        => 'pull-request',
						'body'          => null,
						'pull_requests' => [
							[
								'title'  => 'Fix `wp post list`',
								'number' => 12,
								'url'    => 'https://github.com/wp-cli/entity-command/pull/12',
							],
						],
					],
					[
						'milestone'     => '3.0.3',
						'source'        => 'pull-request',
						'body'          => null,
						'pull_requests' => [
							[
								'title'  => 'Add `wp icon`',
								'number' => 15,
								'url'    => 'https://github.com/wp-cli/entity-command/pull/15',
							],
						],
					],
				],
			],
		];
	}

	public function test_render_markdown_with_headings(): void {
		$this->assertSame(
			"#### [wp-cli/wp-cli](https://github.com/wp-cli/wp-cli/)\n\n"
			. "- Release body\n"
			. "#### [wp-cli/entity-command](https://github.com/wp-cli/entity-command/)\n\n"
			. "- Fix `wp post list` [[#12](https://github.com/wp-cli/entity-command/pull/12)]\n"
			. "- Add `wp icon` [[#15](https://github.com/wp-cli/entity-command/pull/15)]\n",
			Release_Notes::render( $this->sections(), 'markdown', true )
		);
	}

	public function test_render_html_without_headings(): void {
		$this->assertSame(
			"- Release body\n"
			. '<ul><li>Fix <code>wp post list</code> [<a href="https://github.com/wp-cli/entity-command/pull/12">#12</a>]</li>'
			. '<li>Add <code>wp icon</code> [<a href="https://github.com/wp-cli/entity-command/pull/15">#15</a>]</li></ul>',
			Release_Notes::render( $this->sections(), 'html', false )
		);
	}

	public function test_render_skips_empty_pull_request_lists(): void {
		$sections = [
			[
				'repo'       => 'wp-cli/wp-cli',
				'milestones' => [
					[
						'milestone'     => '3.0.0',
						'source'        => 'pull-request',
						'body'          => null,
						'pull_requests' => [],
					],
				],
			],
		];

		$this->assertSame( '', Release_Notes::render( $sections, 'html', false ) );
	}
}
