# Barber House integration tests

These tests were moved out of the package's default test suite because they depend on the consuming Barber House application's `App\\Models`, factories, subscriptions, tenant context, and Stancl Tenancy configuration.

They are preserved for execution inside the Barber House application test environment. The package-level command `composer test` intentionally runs only tests that are self-contained and portable with Orchestra Testbench.
