import js from '@eslint/js';
import globals from 'globals';
export default [
    { ignores: ['node_modules/**', 'vendor/**', 'dist/**', 'tests/results/**'] },
    js.configs.recommended,
    { files: ['**/*.js', '**/*.mjs'], languageOptions: { ecmaVersion: 2022, globals: { ...globals.browser, ...globals.node, wp: 'readonly', jQuery: 'readonly' } }, rules: { 'no-unused-vars': ['error', { argsIgnorePattern: '^_', caughtErrors: 'none' }] } }
];
