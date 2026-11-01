import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import FormsList from './FormsList';
import NewForm from './NewForm';
import Builder from './builder/Builder';
import Entries from './Entries';
import SpamLog from './SpamLog';
import SettingsTab from './SettingsTab';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-form-builder' ), icon: 'dashboard' },
	{ id: 'forms', label: __( 'Forms', 'dev3-ai-form-builder' ), icon: 'file' },
	{ id: 'entries', label: __( 'Entries', 'dev3-ai-form-builder' ), icon: 'inbox' },
	{ id: 'spam', label: __( 'Spam shield', 'dev3-ai-form-builder' ), icon: 'bolt' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-form-builder' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-form-builder' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-form-builder' ), icon: 'chart' },
];

export default function App() {
	const [ route ] = useHashRoute( NAV.map( ( n ) => n.id ), 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ meta, setMeta ] = useState( null );
	const [ creds, setCreds ] = useState( null );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
		request( 'meta' ).then( setMeta ).catch( () => {} );
		request( 'credentials' ).then( setCreds ).catch( () => {} );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );
	const usage = () => setMeter( ( n ) => n + 1 );
	const aiReady = !! ( settings && settings.api_key_set && settings.ai_enabled !== false );
	const [ p0, p1 ] = route.params;

	const screens = {
		dashboard: () => <Dashboard settings={ settings } />,
		forms: () => {
			if ( p0 === 'new' ) {
				return <NewForm meta={ meta } aiReady={ aiReady } onUsage={ usage } ai={ p1 === 'ai' } />;
			}
			if ( p0 && /^\d+$/.test( p0 ) ) {
				return <Builder key={ p0 } id={ Number( p0 ) } tab={ p1 } meta={ meta } creds={ creds } aiReady={ aiReady } onUsage={ usage } />;
			}
			return <FormsList />;
		},
		entries: () => <Entries params={ route.params } aiReady={ aiReady } onUsage={ usage } />,
		spam: () => <SpamLog />,
		settings: () => <SettingsTab settings={ settings } save={ save } section={ p0 } onCreds={ setCreds } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-form-builder' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-form-builder' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-form-builder' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-form-builder' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Form Builder for WordPress' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-form-builder' ) }
			className={ route.id === 'forms' && p0 && /^\d+$/.test( p0 ) ? 'is-builder' : '' }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
