/**
 * Folder dialogs: new folder, rename, share folder link, and delete. FolderMenu is the "…" menu
 * beside an open folder's heading; NewFolderButton sits beside the sidebar's Folders heading.
 */
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	MenuGroup,
	MenuItem,
	Modal,
	Notice,
	RadioControl,
	TextControl,
	TreeSelect,
} from '@wordpress/components';
import {
	category,
	link as linkIcon,
	moreVertical,
	pencil,
	plus,
	trash,
} from '@wordpress/icons';
import { deleteFolder } from './api';
import {
	TypedConfirmField,
	errorMessage,
	isTypedConfirm,
} from './delete-dialog';
import { useLibrary } from './library-context';
import { LibraryMenu } from './menu-popover';
import { ShareLinkPanel } from './share-dialog';

/**
 * The folder tree without one folder and everything inside it.
 *
 * @param {Array}  tree     buildFolderTree() output.
 * @param {number} folderId Folder to leave out.
 * @return {Array} The pruned tree.
 */
export const treeWithout = ( tree, folderId ) =>
	tree
		.filter( ( node ) => node.id !== folderId )
		.map( ( node ) => ( {
			...node,
			children: treeWithout( node.children, folderId ),
		} ) );

/**
 * The message for a failed create or rename. WordPress says "term"; people here say "folder".
 *
 * @param {Object} err The api-fetch error.
 * @return {string} Message to show.
 */
function folderSaveError( err ) {
	if ( err?.code === 'term_exists' || err?.code === 'duplicate_term_slug' ) {
		return __(
			'There’s already a folder with that name here.',
			'pressshot'
		);
	}
	return errorMessage(
		err,
		__( 'The folder couldn’t be saved.', 'pressshot' )
	);
}

function ErrorNotice( { error } ) {
	return error ? (
		<Notice status="error" isDismissible={ false }>
			{ error }
		</Notice>
	) : null;
}

function FormButtons( {
	onCancel,
	confirmLabel,
	isBusy,
	disabled,
	isDestructive,
} ) {
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
				type="submit"
				variant="primary"
				isDestructive={ isDestructive }
				isBusy={ isBusy }
				disabled={ isBusy || disabled }
				accessibleWhenDisabled
				__next40pxDefaultSize
			>
				{ confirmLabel }
			</Button>
		</div>
	);
}

export function NewFolderDialog( { parentId = 0, onClose, onCreated } ) {
	const { folderTree, onChange } = useLibrary();
	const [ name, setName ] = useState( '' );
	const [ parent, setParent ] = useState(
		parentId ? String( parentId ) : ''
	);
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const create = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			const folder = await apiFetch( {
				path: '/wp/v2/pressshot-folders',
				method: 'POST',
				data: { name: name.trim(), parent: Number( parent ) || 0 },
			} );
			onChange( {
				message: __( 'Folder created', 'pressshot' ),
				termsChanged: true,
			} );
			onCreated?.( folder.id );
			onClose();
		} catch ( err ) {
			setIsBusy( false );
			setError( folderSaveError( err ) );
		}
	};

	return (
		<Modal
			title={ __( 'New folder', 'pressshot' ) }
			onRequestClose={ onClose }
			size="small"
		>
			<form onSubmit={ create }>
				<div className="pressshot-dialog">
					<ErrorNotice error={ error } />
					<TextControl
						label={ __( 'Name', 'pressshot' ) }
						value={ name }
						onChange={ setName }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TreeSelect
						label={ __( 'Inside', 'pressshot' ) }
						noOptionLabel={ __( 'Top level', 'pressshot' ) }
						tree={ folderTree }
						selectedId={ parent }
						onChange={ ( value ) => setParent( value ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<FormButtons
						onCancel={ onClose }
						confirmLabel={ __( 'Create', 'pressshot' ) }
						isBusy={ isBusy }
						disabled={ ! name.trim() }
					/>
				</div>
			</form>
		</Modal>
	);
}

function RenameFolderDialog( { folder, onClose } ) {
	const { onChange } = useLibrary();
	const [ name, setName ] = useState( folder.name );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const rename = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			await apiFetch( {
				path: `/wp/v2/pressshot-folders/${ folder.id }`,
				method: 'POST',
				data: { name: name.trim() },
			} );
			onChange( {
				message: __( 'Folder renamed', 'pressshot' ),
				termsChanged: true,
			} );
			onClose();
		} catch ( err ) {
			setIsBusy( false );
			setError( folderSaveError( err ) );
		}
	};

	return (
		<Modal
			title={ __( 'Rename folder', 'pressshot' ) }
			onRequestClose={ onClose }
			size="small"
		>
			<form onSubmit={ rename }>
				<div className="pressshot-dialog">
					<ErrorNotice error={ error } />
					<TextControl
						label={ __( 'Name', 'pressshot' ) }
						value={ name }
						onChange={ setName }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<FormButtons
						onCancel={ onClose }
						confirmLabel={ __( 'Rename', 'pressshot' ) }
						isBusy={ isBusy }
						disabled={
							! name.trim() || name.trim() === folder.name
						}
					/>
				</div>
			</form>
		</Modal>
	);
}

function ShareFolderDialog( { folder, onClose } ) {
	return (
		<Modal
			title={ __( 'Share folder link', 'pressshot' ) }
			onRequestClose={ onClose }
		>
			<ShareLinkPanel
				kind="folder"
				id={ folder.id }
				note={ __(
					'Everyone on this site can already see this folder. A link lets anyone who has it see the shots in this folder and its subfolders without signing in.',
					'pressshot'
				) }
				onClose={ onClose }
			/>
		</Modal>
	);
}

function DeleteFolderDialog( { folder, onClose, onDeleted } ) {
	const { folderTree, onChange } = useLibrary();
	const [ mode, setMode ] = useState( 'move' );
	const [ target, setTarget ] = useState(
		folder.parent ? String( folder.parent ) : ''
	);
	const [ typed, setTyped ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const remove = async ( event ) => {
		event.preventDefault();
		// Enter in the field submits the form even while the Delete button is disabled.
		if ( ! isTypedConfirm( typed ) ) {
			return;
		}
		setIsBusy( true );
		setError( null );
		try {
			const result = await deleteFolder(
				folder.id,
				mode,
				mode === 'move' ? Number( target ) || 0 : undefined
			);
			onChange( {
				message:
					mode === 'everything' && result.trashed
						? __(
								'Folder deleted and its shots moved to Trash',
								'pressshot'
							)
						: __( 'Folder deleted', 'pressshot' ),
				termsChanged: true,
			} );
			onDeleted?.();
			onClose();
		} catch ( err ) {
			setIsBusy( false );
			let message;
			if ( err?.code === 'pressshot_folder_not_yours' ) {
				message = __(
					'This folder holds shots you can’t delete. Move them instead.',
					'pressshot'
				);
			} else if ( err?.code === 'pressshot_link_exposure' ) {
				message = `${ errorMessage( err, '' ) } ${ __(
					'Pick another folder for what’s inside.',
					'pressshot'
				) }`;
			}
			setError(
				message ||
					errorMessage(
						err,
						__( 'The folder couldn’t be deleted.', 'pressshot' )
					)
			);
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: folder name. */
				__( 'Delete “%s”?', 'pressshot' ),
				folder.name
			) }
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ remove }>
				<div className="pressshot-dialog">
					<ErrorNotice error={ error } />
					<RadioControl
						label={ __(
							'What happens to the shots and folders inside it?',
							'pressshot'
						) }
						selected={ mode }
						options={ [
							{
								label: __(
									'Move its contents to',
									'pressshot'
								),
								value: 'move',
							},
							{
								label: __(
									'Delete everything in it',
									'pressshot'
								),
								value: 'everything',
							},
						] }
						onChange={ setMode }
					/>
					{ mode === 'move' && (
						<TreeSelect
							label={ __( 'Move its contents to', 'pressshot' ) }
							hideLabelFromVision
							noOptionLabel={ __( 'All shots', 'pressshot' ) }
							tree={ treeWithout( folderTree, folder.id ) }
							selectedId={ target }
							onChange={ ( value ) => setTarget( value ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					) }
					{ mode === 'everything' && (
						<p>
							{ __(
								'Its shots, and the shots in its subfolders, go to Trash. Its subfolders are deleted.',
								'pressshot'
							) }
						</p>
					) }
					<TypedConfirmField value={ typed } onChange={ setTyped } />
					<FormButtons
						onCancel={ onClose }
						confirmLabel={ __( 'Delete', 'pressshot' ) }
						isBusy={ isBusy }
						disabled={ ! isTypedConfirm( typed ) }
						isDestructive
					/>
				</div>
			</form>
		</Modal>
	);
}

/** "New folder" beside the sidebar's Folders heading. */
export function NewFolderButton() {
	const [ isOpen, setIsOpen ] = useState( false );
	return (
		<>
			<Button
				icon={ plus }
				label={ __( 'New folder', 'pressshot' ) }
				size="small"
				onClick={ () => setIsOpen( true ) }
			/>
			{ isOpen && (
				<NewFolderDialog onClose={ () => setIsOpen( false ) } />
			) }
		</>
	);
}

/**
 * The open folder's actions menu.
 *
 * @param {Object}   props
 * @param {Object}   props.folder       The folder term { id, name, parent }.
 * @param {Function} props.onDeleted    Called after the folder is deleted.
 * @param {Function} props.onOpenFolder Opens a folder by id (used after creating a subfolder).
 */
export function FolderMenu( { folder, onDeleted, onOpenFolder } ) {
	const { canShareFolders } = useLibrary();
	const [ dialog, setDialog ] = useState( null );
	const close = () => setDialog( null );

	return (
		<>
			<LibraryMenu
				icon={ moreVertical }
				label={ __( 'Folder actions', 'pressshot' ) }
				toggleProps={ { size: 'compact' } }
				align="start"
			>
				{ ( { onClose } ) => (
					<MenuGroup>
						{ [
							[
								'new',
								category,
								__( 'New folder inside', 'pressshot' ),
							],
							[ 'rename', pencil, __( 'Rename', 'pressshot' ) ],
							canShareFolders && [
								'share',
								linkIcon,
								__( 'Share folder link', 'pressshot' ),
							],
							[
								'delete',
								trash,
								__( 'Delete folder', 'pressshot' ),
							],
						]
							.filter( Boolean )
							.map( ( [ key, icon, label ] ) => (
								<MenuItem
									key={ key }
									icon={ icon }
									iconPosition="left"
									isDestructive={ key === 'delete' }
									onClick={ () => {
										onClose();
										setDialog( key );
									} }
								>
									{ label }
								</MenuItem>
							) ) }
					</MenuGroup>
				) }
			</LibraryMenu>
			{ dialog === 'new' && (
				<NewFolderDialog
					parentId={ folder.id }
					onClose={ close }
					onCreated={ onOpenFolder }
				/>
			) }
			{ dialog === 'rename' && (
				<RenameFolderDialog folder={ folder } onClose={ close } />
			) }
			{ dialog === 'share' && (
				<ShareFolderDialog folder={ folder } onClose={ close } />
			) }
			{ dialog === 'delete' && (
				<DeleteFolderDialog
					folder={ folder }
					onClose={ close }
					onDeleted={ onDeleted }
				/>
			) }
		</>
	);
}
