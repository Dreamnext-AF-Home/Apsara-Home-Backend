# Modular Monolith Skeleton

This directory is the target home for business-domain code in the Laravel backend.

Planned module layout:

- `Auth`
- `Customer`
- `Product`
- `Order`
- `Payment`
- `Affiliate`
- `Wallet`
- `Encashment`
- `Supplier`
- `Interior`
- `Cms`
- `Shipping`
- `AiSupport`

Recommended flow:

- `Http/Controllers/Api` keeps HTTP concerns.
- `Domain/*/Actions` contains use-case orchestration.
- `Domain/*/Services` contains reusable business workflows and integrations.
- `Domain/*/DTOs` is used when a module needs explicit request/result data objects.

This commit only scaffolds the folders. No runtime behavior has been changed.
