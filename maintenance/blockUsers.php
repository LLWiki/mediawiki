<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/Maintenance.php';

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\MediaWikiServices;

class BlockUsers extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			"Blocks a list of usernames. Can use STDIN or the file argument.\n\n" .
			'Note, this is probably most useful when dealing with spammers, with a ' .
			"list you've generated with an SQL query or similar.\n\n" .
			'All users are hard blocked, auto blocked from any current and subsequent IP ' .
			'addresses, email disabled, unable to write to their user page and unable to ' .
			'create further accounts with no expiry to this block.'
		);

		$this->addArg(
			'file',
			'File with list of users to block',
			false
		);

		$this->addOption(
			'performer',
			'User to make the blocks',
			true,
			true
		);

		$this->addOption(
			'reason',
			'Reason for the blocks',
			false,
			true
		);
	}

	public function execute() {
		$performer = User::newFromName( $this->getOption( 'performer' ) );

		if ( !$performer ) {
			$this->fatalError( "Username '{$performer}' isn't valid.\n" );
		}

		if ( !$performer->getId() ) {
			$this->fatalError( "User '{$performer}' doesn't exist.\n" );
		}

		$permManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$permManager->userHasAnyRight( $performer, 'block' ) ) {
			$this->fatalError( "User '{$performer}' doesn't have blocking rights.\n" );
		}

		$users = null;
		if ( $this->hasArg( 0 ) ) {
			$users = explode(
				"\n",
				trim( file_get_contents( $this->getArg( 0 ) ) )
			);
		} else {
			$stdin = $this->getStdin();
			$users = [];
			while ( !feof( $stdin ) ) {
				$name = trim( fgets( $stdin ) );
				if ( $name ) {
					$users[] = $name;
				}
			}
		}

		$this->blockUsers(
			$users,
			$performer,
			$this->getOption( 'reason', '' )
		);
	}

	/**
	 * @param string[] $users
	 * @param User $performer
	 * @param string $reason
	 * @throws MWException
	 */
	private function blockUsers( $users, $performer, $reason ) {
		foreach ( $users as $name ) {
			$user = User::newFromName( $name );

			// User is invalid, skip
			if ( !$user || !$user->getId() ) {
				$this->output( "Blocking '{$name}' skipped (user doesn't exist or is invalid).\n" );
				continue;
			}

			if ( $user->getBlock() !== null ) {
				$this->output( "Blocking '{$name}' skipped (user already blocked).\n" );
				continue;
			}

			$block = new DatabaseBlock();
			$block->setTarget( $name );
			$block->setBlocker( $performer );
			$block->setReason( $reason );
			$block->isHardblock( true );
			$block->isAutoblocking( true );
			$block->isCreateAccountBlocked( true );
			$block->isEmailBlocked( true );
			$block->isUsertalkEditAllowed( false );
			$block->setExpiry( SpecialBlock::parseExpiryInput( 'infinity' ) );

			$success = $block->insert();

			if ( $success ) {
				// Log it only if the block was successful
				$flags = [
					'nocreate',
					'noemail',
					'nousertalk',
				];
				$logParams = [
					'5::duration' => 'indefinite',
					'6::flags' => implode( ',', $flags ),
				];

				$logEntry = new ManualLogEntry( 'block', 'block' );
				$logEntry->setTarget( Title::makeTitle( NS_USER, $name ) );
				$logEntry->setComment( $reason );
				$logEntry->setPerformer( $performer );
				$logEntry->setParameters( $logParams );
				$blockIds = array_merge( [ $success['id'] ], $success['autoIds'] );
				$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
				$logEntry->publish( $logEntry->insert() );

				$this->output( "Blocking '{$name}' succeeded.\n" );
			} else {
				$this->output( "Blocking '{$name}' failed.\n" );
			}
		}
	}
}

$maintClass = BlockUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
