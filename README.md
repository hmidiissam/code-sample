# Data Processor — Code Sample

## Overview

This is a selected excerpt from a larger WordPress data-processing component.

The component synchronizes structured real-estate data coming from an external source with WordPress. It is responsible for processing programmes, tranches and lots, deciding whether existing WordPress content should be updated or new content created, and persisting the mapped data.

The complete production class contains additional concerns such as API communication, document handling, logging, metadata updates and import reporting. Those parts are intentionally omitted from this sample.

## What problem does it solve?

The main challenge is synchronizing an external data model with a WordPress content model while keeping the import resilient and predictable.

The processor needs to:

- process an external operation;
- identify and process programmes;
- create or update WordPress programme content;
- create or update associated tranches;
- create or update lots;
- preserve relationships between these entities;
- handle invalid or incomplete data without stopping the complete import.

## Why I implemented it this way

I kept the processor as an orchestration layer rather than putting all the integration logic into WordPress hooks or templates.

The larger implementation uses dedicated components for responsibilities such as:

- API communication;
- data mapping;
- logging;
- import orchestration.

This makes the synchronization logic easier to evolve when the external data structure or WordPress content model changes.

The `createOrUpdate*` methods also make the synchronization intent explicit: the same import can safely encounter an existing WordPress entity or create a new one.

## WordPress integration

The processor works with WordPress content and metadata and uses the WordPress APIs to persist the imported entities.

The important design consideration is that the external source is treated as the source of structured data, while WordPress remains the persistence and presentation layer.

## Error handling

The import is designed to continue processing independent records when possible instead of failing the complete synchronization because of a single invalid record.

Validation and exception handling are kept around the boundaries where external data or WordPress persistence can fail.


## Note
This repository contains an interview-oriented excerpt from a larger production component. Related classes and project-specific dependencies are intentionally not included.
