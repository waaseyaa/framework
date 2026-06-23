# waaseyaa/validation

**Layer 0 — Foundation**

Input validation for Waaseyaa applications.

Builds on Symfony Validator (`symfony/validator`) with a set of Waaseyaa-specific constraints for validating request data, entity field values, and configuration inputs. The package ships **constraints + a factory**, not a wrapper validator: there is no `ValidatorInterface`/`ValidationResult`/`ValidationViolation` here — you run the constraints through Symfony's own `Validator` (which returns Symfony `ConstraintViolation`s).

Key classes: `ConstraintFactory`, and the constraint set under `Constraint/` — `AllowedValues`, `EntityExists`, `NotEmpty`, `SafeMarkup`, `UniqueField` (each with its paired `*Validator`).
