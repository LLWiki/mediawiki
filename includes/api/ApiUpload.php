<?php
/**
 *
 *
 * Created on Aug 21, 2008
 *
 * Copyright © 2008 - 2010 Bryan Tong Minh <Bryan.TongMinh@Gmail.com>
 *
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
 */

/**
 * @ingroup API
 */
class ApiUpload extends ApiBase {
	/** @var UploadBase|UploadFromChunks */
	protected $mUpload = null;

	protected $mParams;

	public function execute() {
		// Check whether upload is enabled
		if ( !UploadBase::isEnabled() ) {
			$this->dieUsageMsg( 'uploaddisabled' );
		}

		$user = $this->getUser();

		// Parameter handling
		$this->mParams = $this->extractRequestParams();
		$request = $this->getMain()->getRequest();
		// Check if async mode is actually supported (jobs done in cli mode)
		$this->mParams['async'] = ( $this->mParams['async'] &&
			$this->getConfig()->get( 'EnableAsyncUploads' ) );
		// Add the uploaded file to the params array
		$this->mParams['file'] = $request->getFileName( 'file' );
		$this->mParams['chunk'] = $request->getFileName( 'chunk' );

		// Copy the session key to the file key, for backward compatibility.
		if ( !$this->mParams['filekey'] && $this->mParams['sessionkey'] ) {
			$this->mParams['filekey'] = $this->mParams['sessionkey'];
		}

		// Select an upload module
		try {
			if ( !$this->selectUploadModule() ) {
				return; // not a true upload, but a status request or similar
			} elseif ( !isset( $this->mUpload ) ) {
				$this->dieUsage( 'No upload module set', 'nomodule' );
			}
		} catch ( UploadStashException $e ) { // XXX: don't spam exception log
			list( $msg, $code ) = $this->handleStashException( get_class( $e ), $e->getMessage() );
			$this->dieUsage( $msg, $code );
		}

		// First check permission to upload
		$this->checkPermissions( $user );

		// Fetch the file (usually a no-op)
		/** @var $status Status */
		$status = $this->mUpload->fetchFile();
		if ( !$status->isGood() ) {
			$errors = $status->getErrorsArray();
			$error = array_shift( $errors[0] );
			$this->dieUsage( 'Error fetching file from remote source', $error, 0, $errors[0] );
		}

		// Check if the uploaded file is sane
		if ( $this->mParams['chunk'] ) {
			$maxSize = UploadBase::getMaxUploadSize();
			if ( $this->mParams['filesize'] > $maxSize ) {
				$this->dieUsage( 'The file you submitted was too large', 'file-too-large' );
			}
			if ( !$this->mUpload->getTitle() ) {
				$this->dieUsage( 'Invalid file title supplied', 'internal-error' );
			}
		} elseif ( $this->mParams['async'] && $this->mParams['filekey'] ) {
			// defer verification to background process
		} else {
			wfDebug( __METHOD__ . " about to verify\n" );
			$this->verifyUpload();
		}

		// Check if the user has the rights to modify or overwrite the requested title
		// (This check is irrelevant if stashing is already requested, since the errors
		//  can always be fixed by changing the title)
		if ( !$this->mParams['stash'] ) {
			$permErrors = $this->mUpload->verifyTitlePermissions( $user );
			if ( $permErrors !== true ) {
				$this->dieRecoverableError( $permErrors[0], 'filename' );
			}
		}

		// Get the result based on the current upload context:
		try {
			$result = $this->getContextResult();
			if ( $result['result'] === 'Success' ) {
				$result['imageinfo'] = $this->mUpload->getImageInfo( $this->getResult() );
			}
		} catch ( UploadStashException $e ) { // XXX: don't spam exception log
			list( $msg, $code ) = $this->handleStashException( get_class( $e ), $e->getMessage() );
			$this->dieUsage( $msg, $code );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $result );

		// Cleanup any temporary mess
		$this->mUpload->cleanupTempFile();
	}

	/**
	 * Get an upload result based on upload context
	 * @return array
	 */
	private function getContextResult() {
		$warnings = $this->getApiWarnings();
		if ( $warnings && !$this->mParams['ignorewarnings'] ) {
			// Get warnings formatted in result array format
			return $this->getWarningsResult( $warnings );
		} elseif ( $this->mParams['chunk'] ) {
			// Add chunk, and get result
			return $this->getChunkResult( $warnings );
		} elseif ( $this->mParams['stash'] ) {
			// Stash the file and get stash result
			return $this->getStashResult( $warnings );
		}

		// Check throttle after we've handled warnings
		if ( UploadBase::isThrottled( $this->getUser() )
		) {
			$this->dieUsageMsg( 'actionthrottledtext' );
		}

		// This is the most common case -- a normal upload with no warnings
		// performUpload will return a formatted properly for the API with status
		return $this->performUpload( $warnings );
	}

	/**
	 * Get Stash Result, throws an exception if the file could not be stashed.
	 * @param array $warnings Array of Api upload warnings
	 * @return array
	 */
	private function getStashResult( $warnings ) {
		$result = [];
		$result['result'] = 'Success';
		if ( $warnings && count( $warnings ) > 0 ) {
			$result['warnings'] = $warnings;
		}
		// Some uploads can request they be stashed, so as not to publish them immediately.
		// In this case, a failure to stash ought to be fatal
		$this->performStash( 'critical', $result );

		return $result;
	}

	/**
	 * Get Warnings Result
	 * @param array $warnings Array of Api upload warnings
	 * @return array
	 */
	private function getWarningsResult( $warnings ) {
		$result = [];
		$result['result'] = 'Warning';
		$result['warnings'] = $warnings;
		// in case the warnings can be fixed with some further user action, let's stash this upload
		// and return a key they can use to restart it
		$this->performStash( 'optional', $result );

		return $result;
	}

	/**
	 * Get the result of a chunk upload.
	 * @param array $warnings Array of Api upload warnings
	 * @return array
	 */
	private function getChunkResult( $warnings ) {
		$result = [];

		if ( $warnings && count( $warnings ) > 0 ) {
			$result['warnings'] = $warnings;
		}

		$request = $this->getMain()->getRequest();
		$chunkPath = $request->getFileTempname( 'chunk' );
		$chunkSize = $request->getUpload( 'chunk' )->getSize();
		$totalSoFar = $this->mParams['offset'] + $chunkSize;
		$minChunkSize = $this->getConfig()->get( 'MinUploadChunkSize' );

		// Sanity check sizing
		if ( $totalSoFar > $this->mParams['filesize'] ) {
			$this->dieUsage(
				'Offset plus current chunk is greater than claimed file size', 'invalid-chunk'
			);
		}

		// Enforce minimum chunk size
		if ( $totalSoFar != $this->mParams['filesize'] && $chunkSize < $minChunkSize ) {
			$this->dieUsage(
				"Minimum chunk size is $minChunkSize bytes for non-final chunks", 'chunk-too-small'
			);
		}

		if ( $this->mParams['offset'] == 0 ) {
			$filekey = $this->performStash( 'critical' );
		} else {
			$filekey = $this->mParams['filekey'];

			// Don't allow further uploads to an already-completed session
			$progress = UploadBase::getSessionStatus( $this->getUser(), $filekey );
			if ( !$progress ) {
				// Probably can't get here, but check anyway just in case
				$this->dieUsage( 'No chunked upload session with this key', 'stashfailed' );
			} elseif ( $progress['result'] !== 'Continue' || $progress['stage'] !== 'uploading' ) {
				$this->dieUsage(
					'Chunked upload is already completed, check status for details', 'stashfailed'
				);
			}

			$status = $this->mUpload->addChunk(
				$chunkPath, $chunkSize, $this->mParams['offset'] );
			if ( !$status->isGood() ) {
				$extradata = [
					'offset' => $this->mUpload->getOffset(),
				];

				$this->dieStatusWithCode( $status, 'stashfailed', $extradata );
			}
		}

		// Check we added the last chunk:
		if ( $totalSoFar == $this->mParams['filesize'] ) {
			if ( $this->mParams['async'] ) {
				UploadBase::setSessionStatus(
					$this->getUser(),
					$filekey,
					[ 'result' => 'Poll',
						'stage' => 'queued', 'status' => Status::newGood() ]
				);
				JobQueueGroup::singleton()->push( new AssembleUploadChunksJob(
					Title::makeTitle( NS_FILE, $filekey ),
					[
						'filename' => $this->mParams['filename'],
						'filekey' => $filekey,
						'session' => $this->getContext()->exportSession()
					]
				) );
				$result['result'] = 'Poll';
				$result['stage'] = 'queued';
			} else {
				$status = $this->mUpload->concatenateChunks();
				if ( !$status->isGood() ) {
					UploadBase::setSessionStatus(
						$this->getUser(),
						$filekey,
						[ 'result' => 'Failure', 'stage' => 'assembling', 'status' => $status ]
					);
					$this->dieStatusWithCode( $status, 'stashfailed' );
				}

				// We can only get warnings like 'duplicate' after concatenating the chunks
				$warnings = $this->getApiWarnings();
				if ( $warnings ) {
					$result['warnings'] = $warnings;
				}

				// The fully concatenated file has a new filekey. So remove
				// the old filekey and fetch the new one.
				UploadBase::setSessionStatus( $this->getUser(), $filekey, false );
				$this->mUpload->stash->removeFile( $filekey );
				$filekey = $this->mUpload->getStashFile()->getFileKey();

				$result['result'] = 'Success';
			}
		} else {
			UploadBase::setSessionStatus(
				$this->getUser(),
				$filekey,
				[
					'result' => 'Continue',
					'stage' => 'uploading',
					'offset' => $totalSoFar,
					'status' => Status::newGood(),
				]
			);
			$result['result'] = 'Continue';
			$result['offset'] = $totalSoFar;
		}

		$result['filekey'] = $filekey;

		return $result;
	}

	/**
	 * Stash the file and add the file key, or error information if it fails, to the data.
	 *
	 * @param string $failureMode What to do on failure to stash:
	 *   - When 'critical', use dieStatus() to produce an error response and throw an exception.
	 *     Use this when stashing the file was the primary purpose of the API request.
	 *   - When 'optional', only add a 'stashfailed' key to the data and return null.
	 *     Use this when some error happened for a non-stash upload and we're stashing the file
	 *     only to save the client the trouble of re-uploading it.
	 * @param array &$data API result to which to add the information
	 * @return string|null File key
	 */
	private function performStash( $failureMode, &$data = null ) {
		$isPartial = (bool)$this->mParams['chunk'];
		try {
			$status = $this->mUpload->tryStashFile( $this->getUser(), $isPartial );

			if ( $status->isGood() && !$status->getValue() ) {
				// Not actually a 'good' status...
				$status->fatal( new ApiRawMessage( 'Invalid stashed file', 'stashfailed' ) );
			}
		} catch ( Exception $e ) {
			$debugMessage = 'Stashing temporary file failed: ' . get_class( $e ) . ' ' . $e->getMessage();
			wfDebug( __METHOD__ . ' ' . $debugMessage . "\n" );
			$status = Status::newFatal( new ApiRawMessage( $e->getMessage(), 'stashfailed' ) );
		}

		if ( $status->isGood() ) {
			$stashFile = $status->getValue();
			$data['filekey'] = $stashFile->getFileKey();
			// Backwards compatibility
			$data['sessionkey'] = $data['filekey'];
			return $data['filekey'];
		}

		if ( $status->getMessage()->getKey() === 'uploadstash-exception' ) {
			// The exceptions thrown by upload stash code and pretty silly and UploadBase returns poor
			// Statuses for it. Just extract the exception details and parse them ourselves.
			list( $exceptionType, $message ) = $status->getMessage()->getParams();
			$debugMessage = 'Stashing temporary file failed: ' . $exceptionType . ' ' . $message;
			wfDebug( __METHOD__ . ' ' . $debugMessage . "\n" );
			list( $msg, $code ) = $this->handleStashException( $exceptionType, $message );
			$status = Status::newFatal( new ApiRawMessage( $msg, $code ) );
		}

		// Bad status
		if ( $failureMode !== 'optional' ) {
			$this->dieStatus( $status );
		} else {
			list( $code, $msg ) = $this->getErrorFromStatus( $status );
			$data['stashfailed'] = $msg;
			return null;
		}
	}

	/**
	 * Throw an error that the user can recover from by providing a better
	 * value for $parameter
	 *
	 * @param array|string|MessageSpecifier $error Error suitable for passing to dieUsageMsg()
	 * @param string $parameter Parameter that needs revising
	 * @param array $data Optional extra data to pass to the user
	 * @param string $code Error code to use if the error is unknown
	 * @throws UsageException
	 */
	private function dieRecoverableError( $error, $parameter, $data = [], $code = 'unknownerror' ) {
		$this->performStash( 'optional', $data );
		$data['invalidparameter'] = $parameter;

		$parsed = $this->parseMsg( $error );
		if ( isset( $parsed['data'] ) ) {
			$data = array_merge( $data, $parsed['data'] );
		}
		if ( $parsed['code'] === 'unknownerror' ) {
			$parsed['code'] = $code;
		}

		$this->dieUsage( $parsed['info'], $parsed['code'], 0, $data );
	}

	/**
	 * Like dieStatus(), but always uses $overrideCode for the error code, unless the code comes from
	 * IApiMessage.
	 *
	 * @param Status $status
	 * @param string $overrideCode Error code to use if there isn't one from IApiMessage
	 * @param array|null $moreExtraData
	 * @throws UsageException
	 */
	public function dieStatusWithCode( $status, $overrideCode, $moreExtraData = null ) {
		$extraData = null;
		list( $code, $msg ) = $this->getErrorFromStatus( $status, $extraData );
		$errors = $status->getErrorsByType( 'error' ) ?: $status->getErrorsByType( 'warning' );
		if ( !( $errors[0]['message'] instanceof IApiMessage ) ) {
			$code = $overrideCode;
		}
		if ( $moreExtraData ) {
			$extraData = $extraData ?: [];
			$extraData += $moreExtraData;
		}
		$this->dieUsage( $msg, $code, 0, $extraData );
	}

	/**
	 * Select an upload module and set it to mUpload. Dies on failure. If the
	 * request was a status request and not a true upload, returns false;
	 * otherwise true
	 *
	 * @return bool
	 */
	protected function selectUploadModule() {
		$request = $this->getMain()->getRequest();

		// chunk or one and only one of the following parameters is needed
		if ( !$this->mParams['chunk'] ) {
			$this->requireOnlyOneParameter( $this->mParams,
				'filekey', 'file', 'url' );
		}

		// Status report for "upload to stash"/"upload from stash"
		if ( $this->mParams['filekey'] && $this->mParams['checkstatus'] ) {
			$progress = UploadBase::getSessionStatus( $this->getUser(), $this->mParams['filekey'] );
			if ( !$progress ) {
				$this->dieUsage( 'No result in status data', 'missingresult' );
			} elseif ( !$progress['status']->isGood() ) {
				$this->dieStatusWithCode( $progress['status'], 'stashfailed' );
			}
			if ( isset( $progress['status']->value['verification'] ) ) {
				$this->checkVerification( $progress['status']->value['verification'] );
			}
			if ( isset( $progress['status']->value['warnings'] ) ) {
				$warnings = $this->transformWarnings( $progress['status']->value['warnings'] );
				if ( $warnings ) {
					$progress['warnings'] = $warnings;
				}
			}
			unset( $progress['status'] ); // remove Status object
			$this->getResult()->addValue( null, $this->getModuleName(), $progress );

			return false;
		}

		// The following modules all require the filename parameter to be set
		if ( is_null( $this->mParams['filename'] ) ) {
			$this->dieUsageMsg( [ 'missingparam', 'filename' ] );
		}

		if ( $this->mParams['chunk'] ) {
			// Chunk upload
			$this->mUpload = new UploadFromChunks( $this->getUser() );
			if ( isset( $this->mParams['filekey'] ) ) {
				if ( $this->mParams['offset'] === 0 ) {
					$this->dieUsage( 'Cannot supply a filekey when offset is 0', 'badparams' );
				}

				// handle new chunk
				$this->mUpload->continueChunks(
					$this->mParams['filename'],
					$this->mParams['filekey'],
					$request->getUpload( 'chunk' )
				);
			} else {
				if ( $this->mParams['offset'] !== 0 ) {
					$this->dieUsage( 'Must supply a filekey when offset is non-zero', 'badparams' );
				}

				// handle first chunk
				$this->mUpload->initialize(
					$this->mParams['filename'],
					$request->getUpload( 'chunk' )
				);
			}
		} elseif ( isset( $this->mParams['filekey'] ) ) {
			// Upload stashed in a previous request
			if ( !UploadFromStash::isValidKey( $this->mParams['filekey'] ) ) {
				$this->dieUsageMsg( 'invalid-file-key' );
			}

			$this->mUpload = new UploadFromStash( $this->getUser() );
			// This will not download the temp file in initialize() in async mode.
			// We still have enough information to call checkWarnings() and such.
			$this->mUpload->initialize(
				$this->mParams['filekey'], $this->mParams['filename'], !$this->mParams['async']
			);
		} elseif ( isset( $this->mParams['file'] ) ) {
			$this->mUpload = new UploadFromFile();
			$this->mUpload->initialize(
				$this->mParams['filename'],
				$request->getUpload( 'file' )
			);
		} elseif ( isset( $this->mParams['url'] ) ) {
			// Make sure upload by URL is enabled:
			if ( !UploadFromUrl::isEnabled() ) {
				$this->dieUsageMsg( 'copyuploaddisabled' );
			}

			if ( !UploadFromUrl::isAllowedHost( $this->mParams['url'] ) ) {
				$this->dieUsageMsg( 'copyuploadbaddomain' );
			}

			if ( !UploadFromUrl::isAllowedUrl( $this->mParams['url'] ) ) {
				$this->dieUsageMsg( 'copyuploadbadurl' );
			}

			$this->mUpload = new UploadFromUrl;
			$this->mUpload->initialize( $this->mParams['filename'],
				$this->mParams['url'] );
		}

		return true;
	}

	/**
	 * Checks that the user has permissions to perform this upload.
	 * Dies with usage message on inadequate permissions.
	 * @param User $user The user to check.
	 */
	protected function checkPermissions( $user ) {
		// Check whether the user has the appropriate permissions to upload anyway
		$permission = $this->mUpload->isAllowed( $user );

		if ( $permission !== true ) {
			if ( !$user->isLoggedIn() ) {
				$this->dieUsageMsg( [ 'mustbeloggedin', 'upload' ] );
			}

			$this->dieUsageMsg( 'badaccess-groups' );
		}

		// Check blocks
		if ( $user->isBlocked() ) {
			$this->dieBlocked( $user->getBlock() );
		}

		// Global blocks
		if ( $user->isBlockedGlobally() ) {
			$this->dieBlocked( $user->getGlobalBlock() );
		}
	}

	/**
	 * Performs file verification, dies on error.
	 */
	protected function verifyUpload() {
		$verification = $this->mUpload->verifyUpload();
		if ( $verification['status'] === UploadBase::OK ) {
			return;
		}

		$this->checkVerification( $verification );
	}

	/**
	 * Performs file verification, dies on error.
	 * @param array $verification
	 */
	protected function checkVerification( array $verification ) {
		// @todo Move them to ApiBase's message map
		switch ( $verification['status'] ) {
			// Recoverable errors
			case UploadBase::MIN_LENGTH_PARTNAME:
				$this->dieRecoverableError( 'filename-tooshort', 'filename' );
				break;
			case UploadBase::ILLEGAL_FILENAME:
				$this->dieRecoverableError( 'illegal-filename', 'filename',
					[ 'filename' => $verification['filtered'] ] );
				break;
			case UploadBase::FILENAME_TOO_LONG:
				$this->dieRecoverableError( 'filename-toolong', 'filename' );
				break;
			case UploadBase::FILETYPE_MISSING:
				$this->dieRecoverableError( 'filetype-missing', 'filename' );
				break;
			case UploadBase::WINDOWS_NONASCII_FILENAME:
				$this->dieRecoverableError( 'windows-nonascii-filename', 'filename' );
				break;

			// Unrecoverable errors
			case UploadBase::EMPTY_FILE:
				$this->dieUsage( 'The file you submitted was empty', 'empty-file' );
				break;
			case UploadBase::FILE_TOO_LARGE:
				$this->dieUsage( 'The file you submitted was too large', 'file-too-large' );
				break;

			case UploadBase::FILETYPE_BADTYPE:
				$extradata = [
					'filetype' => $verification['finalExt'],
					'allowed' => array_values( array_unique( $this->getConfig()->get( 'FileExtensions' ) ) )
				];
				ApiResult::setIndexedTagName( $extradata['allowed'], 'ext' );

				$msg = 'Filetype not permitted: ';
				if ( isset( $verification['blacklistedExt'] ) ) {
					$msg .= implode( ', ', $verification['blacklistedExt'] );
					$extradata['blacklisted'] = array_values( $verification['blacklistedExt'] );
					ApiResult::setIndexedTagName( $extradata['blacklisted'], 'ext' );
				} else {
					$msg .= $verification['finalExt'];
				}
				$this->dieUsage( $msg, 'filetype-banned', 0, $extradata );
				break;
			case UploadBase::VERIFICATION_ERROR:
				$parsed = $this->parseMsg( $verification['details'] );
				$info = "This file did not pass file verification: {$parsed['info']}";
				if ( $verification['details'][0] instanceof IApiMessage ) {
					$code = $parsed['code'];
				} else {
					// For backwards-compatibility, all of the errors from UploadBase::verifyFile() are
					// reported as 'verification-error', and the real error code is reported in 'details'.
					$code = 'verification-error';
				}
				if ( $verification['details'][0] instanceof IApiMessage ) {
					$msg = $verification['details'][0];
					$details = array_merge( [ $msg->getKey() ], $msg->getParams() );
				} else {
					$details = $verification['details'];
				}
				ApiResult::setIndexedTagName( $details, 'detail' );
				$data = [ 'details' => $details ];
				if ( isset( $parsed['data'] ) ) {
					$data = array_merge( $data, $parsed['data'] );
				}

				$this->dieUsage( $info, $code, 0, $data );
				break;
			case UploadBase::HOOK_ABORTED:
				if ( is_array( $verification['error'] ) ) {
					$params = $verification['error'];
				} elseif ( $verification['error'] !== '' ) {
					$params = [ $verification['error'] ];
				} else {
					$params = [ 'hookaborted' ];
				}
				$key = array_shift( $params );
				$msg = $this->msg( $key, $params )->inLanguage( 'en' )->useDatabase( false )->text();
				$this->dieUsage( $msg, 'hookaborted', 0, [ 'details' => $verification['error'] ] );
				break;
			default:
				$this->dieUsage( 'An unknown error occurred', 'unknown-error',
					0, [ 'details' => [ 'code' => $verification['status'] ] ] );
				break;
		}
	}

	/**
	 * Check warnings.
	 * Returns a suitable array for inclusion into API results if there were warnings
	 * Returns the empty array if there were no warnings
	 *
	 * @return array
	 */
	protected function getApiWarnings() {
		$warnings = $this->mUpload->checkWarnings();

		return $this->transformWarnings( $warnings );
	}

	protected function transformWarnings( $warnings ) {
		if ( $warnings ) {
			// Add indices
			ApiResult::setIndexedTagName( $warnings, 'warning' );

			if ( isset( $warnings['duplicate'] ) ) {
				$dupes = [];
				/** @var File $dupe */
				foreach ( $warnings['duplicate'] as $dupe ) {
					$dupes[] = $dupe->getName();
				}
				ApiResult::setIndexedTagName( $dupes, 'duplicate' );
				$warnings['duplicate'] = $dupes;
			}

			if ( isset( $warnings['exists'] ) ) {
				$warning = $warnings['exists'];
				unset( $warnings['exists'] );
				/** @var LocalFile $localFile */
				$localFile = isset( $warning['normalizedFile'] )
					? $warning['normalizedFile']
					: $warning['file'];
				$warnings[$warning['warning']] = $localFile->getName();
			}
		}

		return $warnings;
	}

	/**
	 * Handles a stash exception, giving a useful error to the user.
	 * @param string $exceptionType Class name of the exception we encountered.
	 * @param string $message Message of the exception we encountered.
	 * @return array Array of message and code, suitable for passing to dieUsage()
	 */
	protected function handleStashException( $exceptionType, $message ) {
		switch ( $exceptionType ) {
			case 'UploadStashFileNotFoundException':
				return [
					'Could not find the file in the stash: ' . $message,
					'stashedfilenotfound'
				];
			case 'UploadStashBadPathException':
				return [
					'File key of improper format or otherwise invalid: ' . $message,
					'stashpathinvalid'
				];
			case 'UploadStashFileException':
				return [
					'Could not store upload in the stash: ' . $message,
					'stashfilestorage'
				];
			case 'UploadStashZeroLengthFileException':
				return [
					'File is of zero length, and could not be stored in the stash: ' .
						$message,
					'stashzerolength'
				];
			case 'UploadStashNotLoggedInException':
				return [ 'Not logged in: ' . $message, 'stashnotloggedin' ];
			case 'UploadStashWrongOwnerException':
				return [ 'Wrong owner: ' . $message, 'stashwrongowner' ];
			case 'UploadStashNoSuchKeyException':
				return [ 'No such filekey: ' . $message, 'stashnosuchfilekey' ];
			default:
				return [ $exceptionType . ': ' . $message, 'stasherror' ];
		}
	}

	/**
	 * Perform the actual upload. Returns a suitable result array on success;
	 * dies on failure.
	 *
	 * @param array $warnings Array of Api upload warnings
	 * @return array
	 */
	protected function performUpload( $warnings ) {
		// Use comment as initial page text by default
		if ( is_null( $this->mParams['text'] ) ) {
			$this->mParams['text'] = $this->mParams['comment'];
		}

		/** @var $file LocalFile */
		$file = $this->mUpload->getLocalFile();

		// For preferences mode, we want to watch if 'watchdefault' is set,
		// or if the *file* doesn't exist, and either 'watchuploads' or
		// 'watchcreations' is set. But getWatchlistValue()'s automatic
		// handling checks if the *title* exists or not, so we need to check
		// all three preferences manually.
		$watch = $this->getWatchlistValue(
			$this->mParams['watchlist'], $file->getTitle(), 'watchdefault'
		);

		if ( !$watch && $this->mParams['watchlist'] == 'preferences' && !$file->exists() ) {
			$watch = (
				$this->getWatchlistValue( 'preferences', $file->getTitle(), 'watchuploads' ) ||
				$this->getWatchlistValue( 'preferences', $file->getTitle(), 'watchcreations' )
			);
		}

		// Deprecated parameters
		if ( $this->mParams['watch'] ) {
			$watch = true;
		}

		if ( $this->mParams['tags'] ) {
			$status = ChangeTags::canAddTagsAccompanyingChange( $this->mParams['tags'], $this->getUser() );
			if ( !$status->isOK() ) {
				$this->dieStatus( $status );
			}
		}

		// No errors, no warnings: do the upload
		if ( $this->mParams['async'] ) {
			$progress = UploadBase::getSessionStatus( $this->getUser(), $this->mParams['filekey'] );
			if ( $progress && $progress['result'] === 'Poll' ) {
				$this->dieUsage( 'Upload from stash already in progress.', 'publishfailed' );
			}
			UploadBase::setSessionStatus(
				$this->getUser(),
				$this->mParams['filekey'],
				[ 'result' => 'Poll', 'stage' => 'queued', 'status' => Status::newGood() ]
			);
			JobQueueGroup::singleton()->push( new PublishStashedFileJob(
				Title::makeTitle( NS_FILE, $this->mParams['filename'] ),
				[
					'filename' => $this->mParams['filename'],
					'filekey' => $this->mParams['filekey'],
					'comment' => $this->mParams['comment'],
					'tags' => $this->mParams['tags'],
					'text' => $this->mParams['text'],
					'watch' => $watch,
					'session' => $this->getContext()->exportSession()
				]
			) );
			$result['result'] = 'Poll';
			$result['stage'] = 'queued';
		} else {
			/** @var $status Status */
			$status = $this->mUpload->performUpload( $this->mParams['comment'],
				$this->mParams['text'], $watch, $this->getUser(), $this->mParams['tags'] );

			if ( !$status->isGood() ) {
				// Is there really no better way to do this?
				$errors = $status->getErrorsByType( 'error' );
				$msg = array_merge( [ $errors[0]['message'] ], $errors[0]['params'] );
				$data = $status->getErrorsArray();
				ApiResult::setIndexedTagName( $data, 'error' );
				// For backwards-compatibility, we use the 'internal-error' fallback key and merge $data
				// into the root of the response (rather than something sane like [ 'details' => $data ]).
				$this->dieRecoverableError( $msg, null, $data, 'internal-error' );
			}
			$result['result'] = 'Success';
		}

		$result['filename'] = $file->getName();
		if ( $warnings && count( $warnings ) > 0 ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		$params = [
			'filename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'comment' => [
				ApiBase::PARAM_DFLT => ''
			],
			'tags' => [
				ApiBase::PARAM_TYPE => 'tags',
				ApiBase::PARAM_ISMULTI => true,
			],
			'text' => [
				ApiBase::PARAM_TYPE => 'text',
			],
			'watch' => [
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			],
			'watchlist' => [
				ApiBase::PARAM_DFLT => 'preferences',
				ApiBase::PARAM_TYPE => [
					'watch',
					'preferences',
					'nochange'
				],
			],
			'ignorewarnings' => false,
			'file' => [
				ApiBase::PARAM_TYPE => 'upload',
			],
			'url' => null,
			'filekey' => null,
			'sessionkey' => [
				ApiBase::PARAM_DEPRECATED => true,
			],
			'stash' => false,

			'filesize' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 0,
				ApiBase::PARAM_MAX => UploadBase::getMaxUploadSize(),
			],
			'offset' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 0,
			],
			'chunk' => [
				ApiBase::PARAM_TYPE => 'upload',
			],

			'async' => false,
			'checkstatus' => false,
		];

		return $params;
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		return [
			'action=upload&filename=Wiki.png' .
				'&url=http%3A//upload.wikimedia.org/wikipedia/en/b/bc/Wiki.png&token=123ABC'
				=> 'apihelp-upload-example-url',
			'action=upload&filename=Wiki.png&filekey=filekey&ignorewarnings=1&token=123ABC'
				=> 'apihelp-upload-example-filekey',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:Upload';
	}
}
