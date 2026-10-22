# Development roadmap

This roadmap is based on issues found while generating OpenAPI for a large Yii API whose PHPDoc is also consumed by the generator, Psalm, and IDE tooling. The library should accept valid PHPDoc types, produce valid OpenAPI schemas, and report unsupported input without aborting generation.

## P0 — Reliable PHPDoc type handling (in progress)

- [x] Convert generic arrays (`array<K, V>`, `array<T>`, `list<T>`) to array or object schemas.
- [x] Handle associative array shapes (`array{key: Type, optional?: Type}`), nested shapes, generics, and union types.
- [x] Preserve the complete `@return` type and separate it from its description. Previously, splitting the rendered tag at the first space truncated array shapes.
- [x] Report an unresolved response class as a missing schema instead of returning `null` from a method declared to return `Schema` and crashing with `TypeError`.
- [x] Add PHPUnit regressions for generic maps and lists, nested array schemas, shapes, property unions, and complete endpoint return-tag parsing.
- [x] Support location prefixes in `@param`: `[path]`, `[query]`, `[header]`, `[cookie]`, and `[body]`, including wire-name overrides such as `[header:X-Request-Id]`.
- [x] Retain the backward-compatible fallback: without a prefix, primitive types are query parameters, while complex types become JSON body fields when the corresponding setting is enabled.
- [x] Treat a single explicit body argument as the entire request-body schema and combine multiple body arguments into an object; when `CHANGE_GET_TO_POST_FOR_COMPLEX_PARAMETERS` is enabled, a GET endpoint with a request body is generated as POST.
- [x] Recognize path placeholders containing `_`, digits, and hyphens; always mark path parameters as required.
- [x] Fall back to native PHP parameter types when `@param` is absent; preserve literal defaults and do not mistake non-null defaults for nullable values.
- [x] Add location-specific tags `@queryParam`, `@pathParam`, `@headerParam`, `@cookieParam`, and `@bodyParam` for `run()` arguments and external Request values.
- [x] Add `@requestBody` to describe a complete raw request-body schema separately from individual `@bodyParam` fields.
- [x] Support wire names through `name=`, formats through `format=`, and `required`/`optional` markers; retain `@apiParameter` for backward compatibility.
- [ ] Define and test `list{...}` / tuple-shape semantics. OpenAPI 3.0 cannot directly represent positional tuple constraints; choose an approximation or target OpenAPI 3.1.
- [ ] Define `array<K, V>` semantics for non-string keys and validate serialization with an OpenAPI validator.

## P1 — Input parameter types and locations

- [ ] Report conflicts between native PHP types and PHPDoc, and document which one takes precedence.
- [x] Use `ReflectionParameter::getDefaultValue()` for optional parameters: non-null literal defaults are no longer treated as `nullable: true` and are preserved in the schema, including `0` and `false`.
- [x] Always set path parameters to `required: true`, even when the PHP argument is optional; report mismatches with route placeholders.
- [x] Recognize `{parameter}` placeholders containing letters, digits, underscores, and hyphens.
- [x] Support PHPDoc location prefixes: `@param T $arg [query|path|header|cookie|body] Description`. If omitted, retain the legacy rule: primitive types are query parameters, complex types are body fields when enabled.
- [x] Support wire names that differ from PHP variable names, for example `[header:X-Request-Id]`.
- [x] Add location-specific PHPDoc tags for input values not declared as `run()` arguments, including headers, cookies, query, path, and raw request body.
- [x] Support external parameter formats through `format=<value>` and `@paramFormat $name <format>`.
- [x] Document that header/cookie location tags describe the API contract but do not configure runtime binding.
- [ ] Validate mismatches between PHP argument binding and declared header/cookie locations in framework integrations.
- [ ] Consider native PHP attributes or integration metadata if PHPDoc location prefixes are not sufficient.
- [ ] Make PHPDoc array types accurately represent the data. For example, `support/addPoints` uses `@param array $sso_ids` for a list of string user IDs, but the generated items are currently `object`; arrays of structures and maps are similarly often under-specified.
- [ ] Validate `@paramEnum`, `@paramFormat`, and `@paramExample` against existing parameters and their types; these custom tags currently use separate ad-hoc string parsing.
- [ ] Support standard query-array serialization options (`style`, `explode`, `allowReserved`) and settle whether the `[]` suffix should be part of the wire name.
- [x] Add location-specific tags for request values read directly from the Request when they are not method arguments.
- [ ] Support request-body content types other than `application/json`.

## P1 — Successful response types and reusable components

- [ ] Agree on and apply response types across endpoints: `@return array` is not precise enough to distinguish an associative JSON object, list, map, or DTO array. For example, `support/addPoints` returns a map keyed by `sso_id`, whose values are statuses or errors, while generic `array` generates `type: array` with `items: object`.
- [ ] Use precise supported types (DTOs/classes or array shapes after the type parser is complete) and compare schemas with actual marshaller output.
- [ ] Separate request and response schemas. The same model/marshaller does not always describe both directions; currently responses are primarily generated from the return type/marshaller, while request bodies come from complex `@param` values.
- [ ] Move reusable request/response models to `components.schemas` and reference them with `$ref`, including recursion and circular references. Classes and marshaller schemas are currently mostly expanded inline, duplicating contracts across API versions.
- [ ] Define how dynamically/conditionally present fields, different marshallers, and version-specific endpoint overrides are represented; do not present absent or uncomputable fields as reliable schema.

## P1 — Error responses and HTTP status codes

- [ ] Support multiple `@OA\Response` declarations/status codes and mapping exceptions to error response schemas.
- [ ] Define an explicit error contract: exception/type, error code, HTTP status, body structure, and conditions. `@throws` currently does not contribute to responses; an endpoint generally gets only a `200` response from `@return`.
- [ ] Compare generated responses with actual application behavior. AG uses a shared `AgApiResponse` containing `errorCode`, `errorMessage`, `errorTitle`, request ID, and other fields; business errors can be encoded inside this wrapper. Describe success and error branches explicitly rather than assuming that the wrapper represents only success.
- [ ] Allow endpoints to describe multiple success variants, bodyless responses (204/void), raw responses, and different content types without inventing a JSON schema.
- [ ] Verify that each documented `@throws` maps to a response and exclude exceptions that cannot reach the client.

## P1 — Authorization and security

- [ ] Support explicit public endpoints and endpoints requiring multiple schemes, independently of inherited controller authorization.
- [ ] Distinguish OR and AND semantics for security requirements. Currently each entry in `securitySchemes` becomes a separate Security Requirement object, which means OR; requiring multiple schemes simultaneously is not representable.
- [ ] Validate `@auth` values against declared security schemes. An unknown name currently produces a notice, but the reference is still added to the endpoint.
- [ ] Derive authorization behavior from actual routing/filter configuration, including `getAuthMethods`, exclusions, and inheritance; verify it against `@auth` and controller behavior.
- [ ] Validate security scheme parameters: query/header/cookie location, parameter name, Bearer/OAuth scopes, descriptions, and ensure no secrets enter the specification.

## P2 — API consistency checks

- [ ] Report differences between route inventory, native PHP signatures, PHPDoc, marshaller schemas, and generated YAML.
- [ ] Add end-to-end fixtures for optional/default/null parameters, path placeholders, errors, multiple auth schemes, complex arrays, DTOs, wrappers, and version overrides.
- [ ] Validate specifications with an OpenAPI validator and test for incorrect `items: object`, missing properties, unresolved `$ref`/security schemes, and unexpected endpoint omissions.
- [ ] Publish generator diagnostics as CI artifacts with a per-specification summary.

## Evidence from the current AG generation

- A local full run created 64 YAML files but logged errors for 37 `novelty/select` variants and 26 `feed/getPolls` variants. The presence of YAML files alone does not guarantee endpoint completeness.
- For `support/addPoints`, `@param array $sso_ids` generates `items: object`, although the implementation iterates over string user IDs.
- For `support/addPoints`, the actual `@return array` is a map keyed by SSO ID, but OpenAPI declares an array of objects.
- `DefaultGenerator` catches errors for individual paths without necessarily returning a non-zero exit code. CI needs a separate completeness check even when the generator exits and writes specifications.

## Integration findings

- phpDocumentor rendered the valid `@return array<string, mixed>` as `array<string,mixed>`. The generator treated the whole string as a class name, reported a missing class, then crashed with `TypeError` because the class describer returned `null` from a method declared to return `Schema`.
- A valid `@return array{success: bool, ...}` was truncated at its first space while parsing the rendered tag, so only part of the type reached schema generation.
- Regression tests cover these cases and motivate prioritizing type parsing and diagnostics.
