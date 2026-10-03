# Architecture v0.2

```text
Symcon variables
      |
      v
Environment/Blind State
      |
      v
Pure SHDEngine
      |
      v
Decision + Reason + Priority
      |
      +----> Dashboard / JSON diagnostics
      |
      X  actuator driver intentionally absent in v0.2
```

## Public-driver strategy

v0.3+ will introduce a driver boundary:

```text
SHDDecision
    |
    v
CommandArbiter
    |
    +-- GenericVariableDriver
    +-- KNXVariableDriver
```

The command arbiter will enforce:

- position deadband
- slat deadband
- minimum command interval
- feedback validation
- safety lock
- stale input rejection

The decision engine remains device-independent.
