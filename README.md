# SmartShading for IP-Symcon — v0.2

v0.2 turns the proof of concept into a usable IP-Symcon configuration module.

## New in v0.2

- native editable `List` for blinds
- individual edit dialog per blind
- legacy discovery appears as yellow migration rows
- configuration validation
- position/slat feedback fields
- actuator target fields prepared but not used yet
- automatic non-destructive status-variable name heuristics
- HTML diagnostics dashboard
- actual position/slat included in state
- no actuator writes

## Safety / current behavior

**v0.2 never writes to any actuator.**
`ShadowMode` remains visible, but the driver is intentionally not implemented yet.

This lets you install the module in the real house and validate discovery and decisions safely.

## Suggested first deployment

1. Put this folder into a Git repository.
2. Add repository via IP-Symcon Module Control.
3. Create one `SmartShading` instance.
4. Keep `ShadowMode` enabled.
5. Assign global sensor variables.
6. Review yellow legacy blind rows.
7. Correct facade azimuths and room mappings.
8. Assign position/slat status variables where already present.
9. Apply configuration.
10. Watch the Dashboard for several days.

## Blind list fields

Each blind can store:

- enabled
- name
- room
- type
- facade azimuth
- optional sun entry/exit angle
- position control variable
- position feedback variable
- slat control variable
- slat feedback variable
- room temperature
- room setpoint
- door contact
- privacy day/night values

## Migration principle

The old house configuration is only an import source.
The target architecture is a self-contained module property list so future users do not need the historic script structure.
