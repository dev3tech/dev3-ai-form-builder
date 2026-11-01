/**
 * Shared engine fixtures: the same JSON files run in PHPUnit (EngineTest.php), so the browser
 * and the server always agree on logic, formulas and totals.
 */
import logicCases from '../fixtures/logic-cases.json';
import formulaCases from '../fixtures/formula-cases.json';
import computeCases from '../fixtures/compute-cases.json';
import { compare, evaluate, visible } from '../../src/shared/logic';
import { evaluate as formula, references, tryEvaluate } from '../../src/shared/formula';
import { run } from '../../src/shared/compute';

describe( 'logic fixtures', () => {
	test.each( logicCases.compare.map( ( c ) => [ `${ c.op } ${ JSON.stringify( c.actual ) } ${ JSON.stringify( c.value ) }`, c ] ) )( 'compare %s', ( name, c ) => {
		expect( compare( c.op, c.actual, c.value ) ).toBe( c.expected );
	} );
	test.each( logicCases.visible.map( ( c, i ) => [ i, c ] ) )( 'visible #%d', ( i, c ) => {
		expect( visible( c.logic, c.values ) ).toBe( c.expected );
	} );
	test.each( logicCases.evaluate.map( ( c, i ) => [ i, c ] ) )( 'evaluate #%d', ( i, c ) => {
		const out = evaluate( c.definition, c.values );
		expect( out.fields ).toEqual( c.fields );
		expect( out.steps ).toEqual( c.steps );
		Object.keys( c.fields ).forEach( ( id ) => {
			if ( ! c.fields[ id ] ) {
				expect( out.values[ id ] ).toBe( '' );
			}
		} );
	} );
} );

describe( 'formula fixtures', () => {
	test.each( formulaCases.map( ( c ) => [ c.formula, c ] ) )( '%s', ( name, c ) => {
		if ( c.error ) {
			expect( tryEvaluate( c.formula, c.vars ).error ).not.toBe( '' );
			expect( () => formula( c.formula, c.vars ) ).toThrow();
			return;
		}
		expect( formula( c.formula, c.vars ) ).toBeCloseTo( c.expected, 6 );
	} );
	test( 'references', () => {
		expect( references( '{a} + {b_2} * {a}' ) ).toEqual( [ 'a', 'b_2' ] );
	} );
} );

describe( 'compute fixtures', () => {
	test.each( computeCases.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		const out = run( c.fields, c.values, c.visible );
		expect( out.total ).toBeCloseTo( c.total, 2 );
		Object.keys( c.calc ).forEach( ( id ) => expect( out.values[ id ] ).toBe( c.calc[ id ] ) );
	} );
} );
