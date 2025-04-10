This page has versioning notes about version numbers, deterministic builds, pinning, vendors, and discussion from peers. Suggestions are welcome.
- [Introduction](#introduction)
  - [Do this first](#do-this-first)
  - [What is a version identifier?](#what-is-a-version-identifier)
- [Types of version changes](#types-of-version-changes)
  - [Bug fix change](#bug-fix-change)
  - [Non-breaking change](#non-breaking-change)
  - [Breaking change](#breaking-change)
- [Types of versioning](#types-of-versioning)
  - [Semantic versioning](#semantic-versioning)
  - [Increment versioning](#date-versioning)

## Introduction

Versioning software can be challenging. There's terminology to learn and many tradeoffs.

### Do this first

If you're new to versioning or just want to know what to do first, we recommend these steps:

- Use semantic versioning.
- Use deterministic builds, including pinning, resolutions, lock files, and vendors.
- Add the dependency resolution files, e.g. the lock files, to your version control.
- Use automatic testing to verify your dependencies and their upgrades.


### What is a version identifier?

A version identifier is a way to help people understand these kinds of questions:

- Is this version better/newer than this other version?
- Will upgrading to this new version break anything?
- Do I need to upgrade this to be secure?

A version identifier is typically a complex data type made up of iterable data types. 

- A version identifier isn't typically a simple string, nor a simple number.
- A version identifier is also known as a version number, even though it's not a number.

Examples:

- A string of numbers and dots, such as "1.2.3", to indicate major version 1, minor version 2 and micro version 3.
- An incrementing number, such as "123", to indicate the 123rd release or build.


## Types of versioning

### Semantic versioning

Semantic versioning is a simple set of rules and requirements that dictate how version numbers are assigned and incremented.

Given a version number MAJOR.MINOR.PATCH, increment the:

- MAJOR version when you make incompatible API changes, i.e. breaking changes.
- MINOR version when you add functionality in a backwards-compatible manner, i.e. non-breaking changes.
- PATCH version when you make backwards-compatible bug fixes.
- Additional labels for pre-release and build metadata are available as extensions to the MAJOR.MINOR.PATCH format.

See [semver.org](http://semver.org/)

- Pros:
  - Easy to skim.
  - Widely supported by many projects.

- Cons:

    - Some major projects (e.g. Rails) do not support semver and are ok with having significant breakage even at minor versions.


### Increment versioning

Increment versioning is a simple idea to just use a number, and for each new version, just add one.

- For example you would have version 1, version 2, version 3, etc.
- This is very similar to the concept of a "build number", which is an incrementing number that adds one each time a software project is built.

- Pros:
    - Easy to understand.
    - Sortable.

- Cons:

    - Does not indicate the purpose of a version upgrade, such as a fix, new feature, or breaking change.
    - Even a custom enumeration that holds values such as “alpha”, “beta”, "pre" and “RC” isn't just a string, but an enumerated type meant to convey meaning. The fact that they are serialised into a format that happens to be readable and parsable, is beside the point. 
