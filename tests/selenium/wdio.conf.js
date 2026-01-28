import { config as baseConfig } from 'wdio-mediawiki/wdio-defaults.conf.js';

export const config = { ...baseConfig,
	// Override, or add to, the setting from wdio-mediawiki.
	// Learn more at https://webdriver.io/docs/configurationfile/
	//
	// Example:
	// logLevel: 'info',
	maxInstances: 1
};
