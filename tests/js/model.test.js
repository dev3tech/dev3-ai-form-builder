import * as M from '../../src/admin/builder/model';
import { newField, TYPES, hasOptions } from '../../src/admin/builder/fieldTypes';

const def = () => ( {
	steps: [
		{ id: 'step_1', title: 'One', fields: [ { id: 'name', type: 'text', label: 'Name' }, { id: 'email', type: 'email', label: 'Email' } ] },
		{ id: 'step_2', title: 'Two', fields: [ { id: 'msg', type: 'textarea', label: 'Message', logic: { enabled: true, action: 'show', match: 'all', rules: [ { field: 'email', op: 'not_empty', value: '' } ] } } ] },
	],
	notifications: [ { id: 'n1', to: '{field:email}', reply_to: '{field:email}', subject: 'Hi', message: 'From {field:email}', logic: null } ],
	confirmations: [ { id: 'c1', message: 'Thanks {field:name}', url: '', logic: null } ],
} );

describe( 'field types', () => {
	test( 'registry has 30+ types and every type gets defaults', () => {
		const types = Object.keys( TYPES );
		expect( types.length ).toBeGreaterThanOrEqual( 30 );
		types.forEach( ( t ) => {
			const f = newField( t, 'x' );
			expect( f.type ).toBe( t );
			if ( hasOptions( t ) ) {
				expect( f.options.length ).toBeGreaterThan( 0 );
			}
		} );
	} );
} );

describe( 'builder model', () => {
	test( 'slug and unique ids follow the PHP rules', () => {
		expect( M.slug( 'Ünïcode Name!' ) ).toBe( 'unicode_name' );
		expect( M.slug( '123 abc' ) ).toBe( 'field_123_abc' );
		expect( M.uniqueId( def(), 'Email' ) ).toBe( 'email_2' );
	} );

	test( 'adds after the selected position and returns the new id', () => {
		const { def: next, id } = M.addField( def(), 'phone', 0, 1 );
		expect( next.steps[ 0 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'name', id, 'email' ] );
		expect( id ).toBe( 'phone' );
	} );

	test( 'moves within and across steps', () => {
		let d = M.moveField( def(), 'name', 0, 2 );
		expect( d.steps[ 0 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'email', 'name' ] );
		d = M.moveField( def(), 'name', 1, 0 );
		expect( d.steps[ 0 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'email' ] );
		expect( d.steps[ 1 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'name', 'msg' ] );
	} );

	test( 'nudges across step boundaries', () => {
		const d = M.nudgeField( def(), 'msg', -1 );
		expect( d.steps[ 0 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'name', 'email', 'msg' ] );
		expect( d.steps[ 1 ].fields ).toEqual( [] );
		expect( M.nudgeField( def(), 'name', -1 ) ).toEqual( def() );
	} );

	test( 'duplicate, remove, steps', () => {
		const dup = M.duplicateField( def(), 'email' );
		expect( dup.id ).toBe( 'email_2' );
		expect( dup.def.steps[ 0 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'name', 'email', 'email_2' ] );
		expect( M.removeField( def(), 'email' ).steps[ 0 ].fields ).toHaveLength( 1 );
		const added = M.addStep( def() );
		expect( added.steps.map( ( s ) => s.id ) ).toEqual( [ 'step_1', 'step_2', 'step_3' ] );
		const removed = M.removeStep( def(), 1 );
		expect( removed.steps ).toHaveLength( 1 );
		expect( removed.steps[ 0 ].fields.map( ( f ) => f.id ) ).toEqual( [ 'name', 'email', 'msg' ] );
		expect( M.moveStep( def(), 0, 1 ).steps[ 0 ].id ).toBe( 'step_2' );
	} );

	test( 'rename rewrites logic, formulas and merge tags', () => {
		const d = M.renameField( def(), 'email', 'Work Email' );
		expect( M.findField( d, 'work_email' ) ).toBeTruthy();
		expect( d.steps[ 1 ].fields[ 0 ].logic.rules[ 0 ].field ).toBe( 'work_email' );
		expect( d.notifications[ 0 ].to ).toBe( '{field:work_email}' );
		expect( d.notifications[ 0 ].message ).toBe( 'From {field:work_email}' );
		expect( M.renameField( def(), 'email', 'name' ) ).toEqual( def() );
	} );

	test( 'earlier fields for logic', () => {
		expect( M.earlierFields( def(), 'msg' ).map( ( f ) => f.id ) ).toEqual( [ 'name', 'email' ] );
		expect( M.fieldsBeforeStep( def(), 1 ).map( ( f ) => f.id ) ).toEqual( [ 'name', 'email' ] );
	} );
} );
