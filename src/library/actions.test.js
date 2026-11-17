jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
// The dialogs render components; these tests only check the action rules, and the components
// package pulls in ES modules Jest can't load.
jest.mock( '@wordpress/components', () => ( {} ) );

const {
	needsTypedConfirm,
	isTypedConfirm,
	moveErrorMessage,
	shotActions,
} = require( './actions' );

const shot = ( overrides = {} ) => ( {
	id: 1,
	title: 'A shot',
	canEdit: true,
	canDelete: true,
	trashed: false,
	link: null,
	downloadUrl: 'http://example.test/wp-json/pressshot/v1/shots/1/media?download=1',
	...overrides,
} );

const eligibleIds = ( actions, item ) =>
	actions
		.filter( ( action ) => ! action.isEligible || action.isEligible( item ) )
		.map( ( action ) => action.id );

describe( 'needsTypedConfirm', () => {
	it( 'is false for one shot and true for several', () => {
		expect( needsTypedConfirm( 1 ) ).toBe( false );
		expect( needsTypedConfirm( 2 ) ).toBe( true );
	} );
} );

describe( 'isTypedConfirm', () => {
	it( 'accepts DELETE, ignoring surrounding spaces', () => {
		expect( isTypedConfirm( 'DELETE' ) ).toBe( true );
		expect( isTypedConfirm( ' DELETE ' ) ).toBe( true );
	} );

	it( 'is case-sensitive and rejects anything else', () => {
		expect( isTypedConfirm( 'delete' ) ).toBe( false );
		expect( isTypedConfirm( 'DELETE IT' ) ).toBe( false );
		expect( isTypedConfirm( '' ) ).toBe( false );
	} );
} );

describe( 'shotActions', () => {
	const actions = shotActions( { onChange: () => {} } );

	it( 'offers Delete only on shots the person can delete', () => {
		expect( eligibleIds( actions, shot() ) ).toContain( 'delete' );
		expect( eligibleIds( actions, shot( { canDelete: false } ) ) ).not.toContain(
			'delete'
		);
	} );

	it( 'offers Restore only in Trash, and nothing else there', () => {
		expect( eligibleIds( actions, shot() ) ).not.toContain( 'restore' );
		expect( eligibleIds( actions, shot( { trashed: true } ) ) ).toEqual( [
			'restore',
		] );
		expect(
			eligibleIds( actions, shot( { trashed: true, canDelete: false } ) )
		).toEqual( [] );
	} );

	it( 'offers Copy link only when the shot has a live link', () => {
		expect( eligibleIds( actions, shot() ) ).not.toContain( 'copy-link' );
		const link = { url: 'http://example.test/s/abc', expiresAt: null };
		expect( eligibleIds( actions, shot( { link } ) ) ).toContain( 'copy-link' );
		const expired = { ...link, expiresAt: '2000-01-01T00:00:00Z' };
		expect( eligibleIds( actions, shot( { link: expired } ) ) ).not.toContain(
			'copy-link'
		);
	} );

	it( 'offers Share link only to people who can edit the shot', () => {
		expect( eligibleIds( actions, shot() ) ).toContain( 'share-link' );
		expect( eligibleIds( actions, shot( { canEdit: false } ) ) ).not.toContain(
			'share-link'
		);
	} );

	it( 'offers Download only when there is a file to download', () => {
		expect( eligibleIds( actions, shot() ) ).toContain( 'download' );
		expect( eligibleIds( actions, shot( { downloadUrl: null } ) ) ).not.toContain(
			'download'
		);
	} );

	it( 'lets Move, Tag, Delete, and Restore run in bulk, and nothing else', () => {
		const bulk = actions
			.filter( ( action ) => action.supportsBulk )
			.map( ( action ) => action.id )
			.sort();
		expect( bulk ).toEqual( [ 'delete', 'move', 'restore', 'tag' ] );
	} );

	it( 'drops Delete from a selection that holds a shot the person can’t delete', () => {
		const mine = shot( { id: 1 } );
		const theirs = shot( { id: 2, canDelete: false } );
		const other = shot( { id: 3 } );
		const mixed = shotActions( {
			onChange: () => {},
			selectedShots: [ mine, theirs ],
		} );
		expect( eligibleIds( mixed, mine ) ).not.toContain( 'delete' );
		// A shot outside the selection keeps its own Delete.
		expect( eligibleIds( mixed, other ) ).toContain( 'delete' );
		const allMine = shotActions( {
			onChange: () => {},
			selectedShots: [ mine, other ],
		} );
		expect( eligibleIds( allMine, mine ) ).toContain( 'delete' );
	} );
} );

describe( 'moveErrorMessage', () => {
	it( 'keeps the server\'s reason for a shared folder and adds what to do', () => {
		const message = moveErrorMessage(
			{
				code: 'pressshot_link_exposure',
				message: '"Launch" has a share link.',
			},
			'fallback'
		);
		expect( message ).toMatch( /^"Launch" has a share link\. / );
		expect( message ).toContain( 'Pick another folder' );
	} );

	it( 'shows other errors as they are, or the fallback', () => {
		expect(
			moveErrorMessage( { code: 'other', message: 'Nope.' }, 'fallback' )
		).toBe( 'Nope.' );
		expect( moveErrorMessage( {}, 'fallback' ) ).toBe( 'fallback' );
	} );
} );
