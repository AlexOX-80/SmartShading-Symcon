# SmartShading v0.3-shadow architecture

Decision order:

1. Emergency / wind / hail / door / panic
2. Calendar mode
3. Sleep + wake/daylight release
4. Constraints:
   - privacy day/night
   - sleep closure
   - cold-night insulation
5. Comfort targets:
   - overheating
   - sun protection
   - passive solar heat
   - daylight
6. Clamp the selected comfort target to active constraints
7. Log the complete reason trace

`SLEEP` is not intended to block morning daylight forever: a valid wake release removes the sleep
constraint even if the surrounding schedule still represents the sleeping period.

For stairwells/halls, `daylightReleaseID` can point to an `allAwake` group variable.
