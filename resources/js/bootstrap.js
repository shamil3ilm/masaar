// Intentionally empty.
//
// This put axios on window and set one default header. No view references
// axios, and these consoles did not load a bundle at all until 2026-10-12 -
// so wiring the bundle in would have started shipping it, with twelve open
// advisories, to an authenticated admin session for code nothing calls.
//
// Kept as a file rather than deleted so the next person looking for the
// Laravel scaffold's entry point finds this note instead of its absence.
