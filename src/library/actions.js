/**
 * The shot actions DataViews shows on each shot and on a selection, plus the Move to folder and
 * Tag dialogs. The viewer shows the same actions as buttons.
 */
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	FormTokenField,
	Modal,
	Notice,
	TreeSelect,
} from '@wordpress/components';
import {
	backup,
	category,
	copySmall,
	download,
	link as linkIcon,
	tag as tagIcon,
	trash,
} from '@wordpress/icons';
import { organizeShots, restoreShot } from './api';
import DeleteShotsDialog, {
	deleteModalHeader,
	errorMessage,
	isTypedConfirm,
	needsTypedConfirm,
} from './delete-dialog';
import { mediaSrc } from './fields';
import { useLibrary } from './library-context';
import ShareShotDialog, { copyText } from './share-dialog';

export { isTypedConfirm, needsTypedConfirm };

/** The organize route takes at most this many ids per call. */
const ORGANIZE_LIMIT = 100;

/**
 * Runs organizeShots over any number of ids, 100 at a time.
 *
 * @param {number[]} ids    Shot ids.
 * @param {Object}   change { folder?, addTags?, removeTags? }.
 */
async function organizeAll( ids, change ) {
	for ( let start = 0; start < ids.length; start += ORGANIZE_LIMIT ) {
		try {
			await organizeShots(
				ids.slice( start, start + ORGANIZE_LIMIT ),
				change
			);
		} catch ( err ) {
			// Earlier batches already went through, so the caller knows to refresh.
			if ( err && typeof err === 'object' ) {
				err.organizedCount = start;
			}
			throw err;
		}
	}
}

/**
 * The message for a failed move. A folder with a share link refuses shots the person can't share
 * themselves, and the server says which folder; this adds what to do about it.
 *
 * @param {Object} err      The api-fetch error.
 * @param {string} fallback Message when the error has none.
 * @return {string} Message to show.
 */
export function moveErrorMessage( err, fallback ) {
	if ( err?.code === 'pressshot_link_exposure' && err.message ) {
		return `${ err.message } ${ __(
			'Pick another folder, or ask someone who can share them to file them there.',
			'pressshot'
		) }`;
	}
	return errorMessage( err, fallback );
}

const hasLiveLink = ( shot ) =>
	!! shot.link?.url &&
	( ! shot.link.expiresAt || new Date( shot.link.expiresAt ) > new Date() );

function DialogButtons( { onCancel, onConfirm, confirmLabel, isBusy } ) {
	return (
		<div className="pressshot-dialog__buttons">
			<Button
				variant="tertiary"
				onClick={ onCancel }
				disabled={ isBusy }
				__next40pxDefaultSize
			>
				{ __( 'Cancel', 'pressshot' ) }
			</Button>
			<Button
				variant="primary"
				onClick={ onConfirm }
				isBusy={ isBusy }
				disabled={ isBusy }
				accessibleWhenDisabled
				__next40pxDefaultSize
			>
				{ confirmLabel }
			</Button>
		</div>
	);
}

function MoveDialog( { items, closeModal } ) {
	const { folders, folderTree, onChange } = useLibrary();
	const shared = items.every(
		( item ) => item.folderId === items[ 0 ].folderId
	);
	const [ folderId, setFolderId ] = useState(
		shared && items[ 0 ].folderId ? String( items[ 0 ].folderId ) : ''
	);
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const move = async () => {
		setIsBusy( true );
		setError( null );
		const target = Number( folderId ) || 0;
		try {
			await organizeAll(
				items.map( ( item ) => item.id ),
				{ folder: target }
			);
		} catch ( err ) {
			setIsBusy( false );
			setError(
				moveErrorMessage(
					err,
					__( 'The shots couldn’t be moved.', 'pressshot' )
				)
			);
			if ( err?.organizedCount ) {
				onChange();
			}
			return;
		}
		const name = folders.find( ( folder ) => folder.id === target )?.name;
		closeModal?.();
		onChange( {
			message: name
				? sprintf(
						/* translators: %s: folder name. */
						__( 'Moved to %s', 'pressshot' ),
						name
					)
				: __( 'Removed from its folder', 'pressshot' ),
		} );
	};

	return (
		<div className="pressshot-dialog">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<TreeSelect
				label={ __( 'Folder', 'pressshot' ) }
				noOptionLabel={ __( 'No folder', 'pressshot' ) }
				tree={ folderTree }
				selectedId={ folderId }
				onChange={ ( value ) => setFolderId( value ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<DialogButtons
				onCancel={ closeModal }
				onConfirm={ move }
				confirmLabel={ __( 'Move', 'pressshot' ) }
				isBusy={ isBusy }
			/>
		</div>
	);
}

/**
 * The id for a tag name, creating the tag when there's none by that name.
 *
 * @param {string} name Tag name.
 * @param {Array}  tags Loaded tags.
 * @return {Promise<{id: number, created: boolean}>} The tag id and whether it's new.
 */
async function tagIdFor( name, tags ) {
	const known = tags.find(
		( item ) => item.name.toLowerCase() === name.toLowerCase()
	);
	if ( known ) {
		return { id: known.id, created: false };
	}
	try {
		const created = await apiFetch( {
			path: '/wp/v2/pressshot-tags',
			method: 'POST',
			data: { name },
		} );
		return { id: created.id, created: true };
	} catch ( err ) {
		// Someone else made it since the list loaded.
		if ( err?.code === 'term_exists' && err?.data?.term_id ) {
			return { id: err.data.term_id, created: false };
		}
		throw err;
	}
}

function TagDialog( { items, closeModal } ) {
	const { tags, onChange } = useLibrary();
	const commonIds = tags
		.map( ( item ) => item.id )
		.filter( ( id ) =>
			items.every( ( shot ) => shot.tagIds.includes( id ) )
		);
	const nameOf = ( id ) => tags.find( ( item ) => item.id === id )?.name;
	const [ names, setNames ] = useState( () =>
		commonIds.map( nameOf ).filter( Boolean )
	);
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const save = async () => {
		setIsBusy( true );
		setError( null );
		let termsChanged = false;
		try {
			const chosen = [];
			for ( const name of names ) {
				const trimmed = name.trim();
				if ( ! trimmed ) {
					continue;
				}
				const result = await tagIdFor( trimmed, tags );
				termsChanged = termsChanged || result.created;
				chosen.push( result.id );
			}
			const removeTags = commonIds.filter(
				( id ) => ! chosen.includes( id )
			);
			if ( chosen.length || removeTags.length ) {
				await organizeAll(
					items.map( ( item ) => item.id ),
					{ addTags: chosen, removeTags }
				);
			}
		} catch ( err ) {
			setIsBusy( false );
			setError(
				errorMessage(
					err,
					__( 'The tags couldn’t be saved.', 'pressshot' )
				)
			);
			if ( termsChanged ) {
				onChange( { termsChanged } );
			}
			return;
		}
		closeModal?.();
		onChange( { message: __( 'Tags saved', 'pressshot' ), termsChanged } );
	};

	return (
		<div className="pressshot-dialog">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<FormTokenField
				label={ __( 'Tags', 'pressshot' ) }
				value={ names }
				suggestions={ tags.map( ( item ) => item.name ) }
				onChange={ ( tokens ) =>
					setNames(
						tokens.map( ( token ) =>
							typeof token === 'string' ? token : token.value
						)
					)
				}
				__experimentalExpandOnFocus
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ items.length > 1 && (
				<p>
					{ __(
						'Tags you add go on every selected shot. Tags you remove come off all of them.',
						'pressshot'
					) }
				</p>
			) }
			<DialogButtons
				onCancel={ closeModal }
				onConfirm={ save }
				confirmLabel={ __( 'Save', 'pressshot' ) }
				isBusy={ isBusy }
			/>
		</div>
	);
}

function startDownload( url ) {
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.rel = 'noopener';
	anchor.download = '';
	document.body.appendChild( anchor );
	anchor.click();
	anchor.remove();
}

/**
 * The DataViews actions for shots.
 *
 * @param {Object}   options
 * @param {Function} options.onChange      Called after a change: { message?, removedIds? }.
 * @param {Function} options.notify        Shows a snackbar message without refetching.
 * @param {string}   options.nonce         REST nonce for the download URL.
 * @param {Array}    options.selectedShots The selected shots. When one of them can't be
 *                                         deleted, Delete is left off the whole selection.
 * @return {Array} DataViews actions.
 */
export function shotActions( {
	onChange,
	notify = () => {},
	nonce = '',
	selectedShots = [],
} ) {
	const live = ( shot ) => ! shot.trashed;
	const selectionDeletable = selectedShots.every(
		( shot ) => shot.canDelete
	);
	const inSelection = ( shot ) =>
		selectedShots.some( ( item ) => item.id === shot.id );

	return [
		{
			id: 'copy-link',
			label: __( 'Copy link', 'pressshot' ),
			icon: copySmall,
			isEligible: ( shot ) => live( shot ) && hasLiveLink( shot ),
			callback: async ( [ shot ] ) => {
				notify(
					( await copyText( shot.link.url ) )
						? __( 'Link copied', 'pressshot' )
						: __( 'Couldn’t copy the link.', 'pressshot' )
				);
			},
		},
		{
			id: 'share-link',
			label: __( 'Share link', 'pressshot' ),
			icon: linkIcon,
			isEligible: ( shot ) => live( shot ) && shot.canEdit,
			modalHeader: __( 'Share link', 'pressshot' ),
			RenderModal: ShareShotDialog,
		},
		{
			id: 'move',
			label: __( 'Move to folder', 'pressshot' ),
			icon: category,
			supportsBulk: true,
			isEligible: live,
			modalHeader: ( items ) =>
				items.length > 1
					? sprintf(
							/* translators: %d: number of shots. */
							__( 'Move %d shots to a folder', 'pressshot' ),
							items.length
						)
					: __( 'Move to folder', 'pressshot' ),
			RenderModal: MoveDialog,
		},
		{
			id: 'tag',
			label: __( 'Tag', 'pressshot' ),
			icon: tagIcon,
			supportsBulk: true,
			isEligible: live,
			modalHeader: ( items ) =>
				items.length > 1
					? sprintf(
							/* translators: %d: number of shots. */
							__( 'Tag %d shots', 'pressshot' ),
							items.length
						)
					: __( 'Tag', 'pressshot' ),
			RenderModal: TagDialog,
		},
		{
			id: 'download',
			label: __( 'Download', 'pressshot' ),
			icon: download,
			isEligible: ( shot ) => live( shot ) && !! shot.downloadUrl,
			callback: ( [ shot ] ) =>
				startDownload(
					mediaSrc( shot.downloadUrl, { _wpnonce: nonce } )
				),
		},
		{
			id: 'delete',
			label: __( 'Delete', 'pressshot' ),
			icon: trash,
			isDestructive: true,
			supportsBulk: true,
			isEligible: ( shot ) =>
				live( shot ) &&
				shot.canDelete &&
				( ! inSelection( shot ) || selectionDeletable ),
			modalHeader: deleteModalHeader,
			modalSize: 'small',
			RenderModal: DeleteShotsDialog,
		},
		{
			id: 'restore',
			label: __( 'Restore', 'pressshot' ),
			icon: backup,
			supportsBulk: true,
			isEligible: ( shot ) => shot.trashed && shot.canDelete,
			callback: async ( items ) => {
				const restored = [];
				try {
					for ( const shot of items ) {
						await restoreShot( shot.id );
						restored.push( shot.id );
					}
				} catch ( err ) {
					onChange( {
						message: errorMessage(
							err,
							__( 'The shot couldn’t be restored.', 'pressshot' )
						),
						removedIds: restored,
					} );
					return;
				}
				onChange( {
					message: _n(
						'Shot restored',
						'Shots restored',
						restored.length,
						'pressshot'
					),
					removedIds: restored,
				} );
			},
		},
	];
}

/**
 * The viewer's buttons: the same actions as the grid, for one shot.
 *
 * @param {Object} props
 * @param {Object} props.shot    The open shot.
 * @param {Array}  props.actions shotActions() output.
 */
export function ShotActionButtons( { shot, actions } ) {
	const [ active, setActive ] = useState( null );
	const eligible = actions.filter(
		( action ) => ! action.isEligible || action.isEligible( shot )
	);
	const header = ( action ) => {
		const value = action.modalHeader || action.label;
		return typeof value === 'function' ? value( [ shot ] ) : value;
	};
	return (
		<>
			<div className="pressshot-viewer__actions">
				{ eligible.map( ( action ) => (
					<Button
						key={ action.id }
						variant="secondary"
						icon={ action.icon }
						isDestructive={ action.isDestructive }
						onClick={ () =>
							action.RenderModal
								? setActive( action )
								: action.callback( [ shot ] )
						}
						__next40pxDefaultSize
					>
						{ action.label }
					</Button>
				) ) }
			</div>
			{ active && (
				<Modal
					title={ header( active ) }
					size={ active.modalSize || 'medium' }
					onRequestClose={ () => setActive( null ) }
				>
					<active.RenderModal
						items={ [ shot ] }
						closeModal={ () => setActive( null ) }
					/>
				</Modal>
			) }
		</>
	);
}
