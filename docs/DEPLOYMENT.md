# Deployment v0.2

## Local/private

Recommended Git branches:

- `main`: version installed in the house
- `develop`: next version
- feature branches as needed

Install `main` through Module Control.

## First real-house rollout

v0.2 is a shadow deployment:

- it reads values
- it discovers/migrates blinds
- it calculates targets
- it shows diagnostics
- it does NOT call actuator actions

## v0.3 gate

Do not implement control until:

- all facade orientations are verified
- all safety inputs are verified
- position feedback exists or missing feedback is explicitly accepted
- slat feedback exists for venetian blinds
- room mappings are substantially complete
- shadow decisions have been reviewed

## Public release

The public module should not contain house-specific IDs or names.
Legacy migration code can remain as an optional importer but must not be required.
