# CMS AI Plugin Features

Functional definition for `softspring/cms-ai-plugin`.

This plugin is in active development. It is useful for experimentation and early Armonic AI workflows, but the public behavior is not stable yet.

## Purpose

`cms-ai-plugin` provides optional AI-assisted CMS tooling for Armonic. It helps generate structured content payloads from CMS form schemas and validates generated payloads before they are persisted.

## Main Features

- Register as an Armonic CMS plugin with alias `sfs_cms_ai`.
- Provide an admin content lab screen.
- List configured CMS content types and compatible layouts.
- Discover configured Symfony AI platforms and model catalogs.
- Generate schemas for CMS content version forms.
- Prompt AI platforms to generate valid JSON payloads for a selected content type and layout.
- Decode and normalize model responses.
- Validate generated payloads with Symfony forms.
- Show schema, generated payload, raw model response, validation errors, and rendered generated form.
- Persist valid generated payloads as new CMS content with an initial version.

## Dependencies

- `softspring/cms-bundle` for CMS configuration, managers, content entities, and admin form types.
- `softspring/form-schema` for form-to-schema extraction.
- `symfony/ai-bundle` for AI platform integration.

## Extension Points

- Add support for more form fields in `form-schema` when generated schemas are incomplete.
- Add additional AI workflows around the lab service.
- Add MCP tooling in this plugin or in a dedicated plugin if the tool surface grows independently from AI content generation.

## Current Limits

- The admin lab is experimental.
- Generated content must be reviewed before use.
- Model behavior is provider-dependent.
- MCP support is not implemented yet.
- The plugin does not configure AI platforms by itself; the host application must provide them.
