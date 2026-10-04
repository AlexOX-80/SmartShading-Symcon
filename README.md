# SmartShading for IP-Symcon — v0.3-shadow

This release is intentionally a **day simulation / shadow release**. It never writes blind positions,
slat positions or KNX lock objects.

## New in v0.3-shadow

- reconstructs the old per-blind `Aktuelles Programm` calendar state automatically
- maps old calendar values to AUTO / OPEN / CLOSED / MANUAL
- separates safety overrides, constraints and comfort targets
- privacy is a minimum-closure constraint, not a winner
- daylight opens when bright enough, but can be blocked by sleep/wake release
- optional per-room/person/group `wakeReleaseID` and `daylightReleaseID`
- sleep produces a desired KNX control lock, but does not write it yet
- panic input releases sleep lock, requests opening and marks `alertRequested=true`
- only panic requests an alert; ordinary blocked operation does not
- night cold insulation only acts in darkness
- indoor/outdoor brightness ratio can select day/night privacy
- event-based day protocol + hourly snapshots
- protocol JSON can be copied to ChatGPT for questions such as:
  - Why did the kitchen close at 16:20?
  - Which constraint prevented solar heating from opening the blind?
  - When did the stairwell receive daylight release?

## Recommended day test

1. Update the module.
2. Keep Shadow Mode enabled.
3. Assign global sun/outside/radiation values.
4. Review each blind's automatically discovered `calendarModeID`.
5. Optionally assign sleep/wake/group-release variables for selected rooms.
6. Press **Clear day protocol** in the morning.
7. Let the module run for a day.
8. In the evening press **Show today protocol JSON** and copy the result into ChatGPT.

## Important

This release does **not** command actuators and does **not** send KNX locks. It only records what it
*would* do. Live control belongs to a later release after the protocol has been reviewed.
