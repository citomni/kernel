# CitOmni Conventions (Package Stub)

This package follows the **CitOmni organization-wide conventions**:
-> https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md

Package-specific notes/overrides:
- Every class except the abstract Base classes is final. The Base classes (BaseCommand, BaseController, BaseOperation, BaseRepository, BaseService) are the extension points of the kernel.