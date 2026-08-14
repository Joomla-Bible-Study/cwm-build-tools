// Base ESLint flat-config for CWM Joomla extensions.
//
// Consuming projects extend this with project-specific globals or files.
// Example .eslintrc.mjs in a consuming project:
//
//   import baseConfig from './vendor/cwm/build-tools/templates/eslint.config.base.mjs';
//
//   export default [
//       ...baseConfig,
//       {
//           files: ['**/*.js', '**/*.mjs', '**/*.es6.js'],
//           languageOptions: {
//               globals: {
//                   // Project-specific globals
//                   MyExtension: 'readonly',
//                   intlTelInput: 'readonly',
//               },
//           },
//       },
//       {
//           // Optional: override rules for tests
//           files: ['tests/**/*.js', 'tests/**/*.mjs'],
//           rules: {
//               'no-undef': 'off',
//               'prefer-destructuring': 'off',
//           },
//       },
//   ];

import { defineConfig } from 'eslint/config';
import js from '@eslint/js';
import globals from 'globals';

export default defineConfig([
    {
        // ⚠️ These are advisory unless the consumer's lint:js pins this config.
        //
        // ESLint 10 resolves the nearest eslint.config.* per file rather than
        // using only the root one, so a plain `eslint .` walks into any
        // directory carrying its own config — a git submodule, or this package's
        // own install root under vendor/ — and lints those files under *that*
        // config. These ignores never reach them.
        //
        // Seen both ways on Proclaim (build-tools#90): a hard
        // ERR_MODULE_NOT_FOUND when the nested config imported a base file whose
        // vendor tree was not installed, and, more quietly, files listed here
        // being linted regardless — the count dropped 113 → 105 once the config
        // was pinned.
        //
        // The invocation that holds:
        //   eslint --no-config-lookup -c eslint.config.mjs --max-warnings=0 .
        //
        // `composer sync-configs` warns when lint:js omits it.
        ignores: ['**/vendor/', '**/node_modules/', '**/dist/', '**/reports/', 'media/'],
    },

    // ESLint recommended base (no-unused-vars, no-undef, no-redeclare, etc.)
    js.configs.recommended,

    {
        files: ['**/*.js', '**/*.mjs'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },

    {
        files: ['**/*.js', '**/*.mjs', '**/*.es6.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                Joomla: 'readonly',
                bootstrap: 'readonly',
            },
        },
        rules: {
            // --- Style ---
            indent: ['error', 4, { SwitchCase: 1 }],
            'max-len': ['error', 150, 2, {
                ignoreUrls: true,
                ignoreComments: false,
                ignoreRegExpLiterals: true,
                ignoreStrings: true,
                ignoreTemplateLiterals: true,
            }],

            // --- Modern JS ---
            'no-var': 'error',
            'prefer-const': 'error',
            eqeqeq: ['error', 'always', { null: 'ignore' }],

            // --- Code quality ---
            radix: 'error',
            'default-case': 'error',
            'no-shadow': 'error',
            'no-lonely-if': 'error',
            'no-prototype-builtins': 'error',
            'consistent-return': 'error',
            'no-param-reassign': ['error', { props: false }],
            'no-use-before-define': ['error', { functions: false, classes: true, variables: true }],
            'no-plusplus': ['error', { allowForLoopAfterthoughts: true }],
            'prefer-destructuring': ['error', { array: true, object: false }],

            // --- Restricted patterns ---
            'no-restricted-globals': [
                'error',
                'event',
                { name: 'isFinite', message: 'Use Number.isFinite instead.' },
                { name: 'isNaN', message: 'Use Number.isNaN instead.' },
            ],
            'no-restricted-syntax': [
                'error',
                {
                    selector: 'ForInStatement',
                    message: 'for..in iterates over the prototype chain. Use Object.{keys,values,entries} instead.',
                },
                {
                    selector: 'WithStatement',
                    message: '`with` is disallowed in strict mode.',
                },
            ],

            // --- Intentionally off for admin scripts ---
            'no-console': 'off',
            'no-alert': 'off',
        },
    },
]);
