CREATE TABLE wc_source (id TEXT PRIMARY KEY NOT NULL, ordinal INTEGER NOT NULL UNIQUE, original_json TEXT NOT NULL, sha256 TEXT NOT NULL, schema_version TEXT NOT NULL, schema_commit TEXT NOT NULL);
CREATE TABLE wc_entity (
 id TEXT PRIMARY KEY NOT NULL, source_id TEXT NOT NULL REFERENCES wc_source(id), ordinal INTEGER NOT NULL,
 entity_type TEXT NOT NULL, name TEXT, display_number TEXT, shape_json TEXT NOT NULL, extra_json TEXT NOT NULL, custom_data_json TEXT NOT NULL,
 UNIQUE(source_id, ordinal)
);
CREATE INDEX wc_entity_type ON wc_entity(entity_type);
CREATE TABLE wc_identifier (scope TEXT NOT NULL, value TEXT NOT NULL, entity_id TEXT NOT NULL REFERENCES wc_entity(id), ordinal INTEGER NOT NULL, PRIMARY KEY(scope,value), UNIQUE(entity_id,ordinal));
CREATE TABLE wc_extension (id TEXT PRIMARY KEY NOT NULL, entity_id TEXT NOT NULL REFERENCES wc_entity(id), ordinal INTEGER NOT NULL, namespace TEXT NOT NULL, shape_json TEXT NOT NULL, extra_json TEXT NOT NULL, UNIQUE(entity_id,namespace), UNIQUE(entity_id,ordinal));
CREATE TABLE wc_element (
 id TEXT PRIMARY KEY NOT NULL, extension_id TEXT NOT NULL REFERENCES wc_extension(id) DEFERRABLE INITIALLY DEFERRED,
 scene_id TEXT NOT NULL REFERENCES wc_entity(id), local_id TEXT NOT NULL, ordinal INTEGER NOT NULL,
 kind TEXT NOT NULL, text TEXT NOT NULL, sequence INTEGER NOT NULL CHECK(typeof(sequence)='integer'),
 speaker_id TEXT REFERENCES wc_entity(id), speaker_json TEXT NOT NULL, direction_json TEXT NOT NULL,
 shape_json TEXT NOT NULL, extra_json TEXT NOT NULL, UNIQUE(scene_id,local_id), UNIQUE(extension_id,ordinal)
);
CREATE TABLE wc_edit (extension_id TEXT PRIMARY KEY NOT NULL REFERENCES wc_extension(id) DEFERRABLE INITIALLY DEFERRED,
 fps INTEGER NOT NULL CHECK(typeof(fps)='integer' AND fps>0), width INTEGER NOT NULL CHECK(typeof(width)='integer' AND width>0), height INTEGER NOT NULL CHECK(typeof(height)='integer' AND height>0));
CREATE TABLE wc_track (
 id TEXT PRIMARY KEY NOT NULL, extension_id TEXT NOT NULL REFERENCES wc_extension(id) DEFERRABLE INITIALLY DEFERRED,
 ordinal INTEGER NOT NULL, name TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('Video','Audio')),
 duration_frames INTEGER NOT NULL CHECK(typeof(duration_frames)='integer' AND duration_frames>=0), extra_json TEXT NOT NULL, UNIQUE(extension_id,ordinal)
);
CREATE TABLE wc_media (entity_id TEXT PRIMARY KEY NOT NULL REFERENCES wc_entity(id), extension_id TEXT NOT NULL UNIQUE REFERENCES wc_extension(id) DEFERRABLE INITIALLY DEFERRED, selected_version_id TEXT NOT NULL, FOREIGN KEY(entity_id,selected_version_id) REFERENCES wc_asset_version(asset_id,id) DEFERRABLE INITIALLY DEFERRED);
CREATE TABLE wc_asset_version (id TEXT PRIMARY KEY NOT NULL, asset_id TEXT NOT NULL REFERENCES wc_media(entity_id) DEFERRABLE INITIALLY DEFERRED, version_number INTEGER NOT NULL, url TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('image','video','audio')), provenance_json TEXT NOT NULL, created_revision INTEGER NOT NULL REFERENCES wc_revision(id) DEFERRABLE INITIALLY DEFERRED, UNIQUE(asset_id,version_number), UNIQUE(asset_id,id));
CREATE TABLE wc_clip (
 id TEXT PRIMARY KEY NOT NULL, track_id TEXT NOT NULL REFERENCES wc_track(id), ordinal INTEGER NOT NULL,
 asset_id TEXT NOT NULL REFERENCES wc_media(entity_id) DEFERRABLE INITIALLY DEFERRED, asset_ref_json TEXT NOT NULL, name TEXT NOT NULL,
 start_frame INTEGER NOT NULL CHECK(typeof(start_frame)='integer' AND start_frame>=0),
 duration_frames INTEGER NOT NULL CHECK(typeof(duration_frames)='integer' AND duration_frames>0),
 source_start_frame INTEGER NOT NULL CHECK(typeof(source_start_frame)='integer' AND source_start_frame>=0),
 presentation_json TEXT NOT NULL, extra_json TEXT NOT NULL, UNIQUE(track_id,ordinal)
);
CREATE INDEX wc_clip_timing ON wc_clip(track_id,start_frame);
CREATE TABLE wc_shot (
 entity_id TEXT PRIMARY KEY NOT NULL REFERENCES wc_entity(id), extension_id TEXT NOT NULL UNIQUE REFERENCES wc_extension(id) DEFERRABLE INITIALLY DEFERRED,
 scene_id TEXT NOT NULL REFERENCES wc_entity(id), scene_ref_json TEXT NOT NULL,
 element_local_id TEXT, sequence INTEGER, framing TEXT,
 start_frame INTEGER CHECK(start_frame IS NULL OR (typeof(start_frame)='integer' AND start_frame>=0)),
 duration_frames INTEGER CHECK(duration_frames IS NULL OR (typeof(duration_frames)='integer' AND duration_frames>0)),
 selected_asset_id TEXT REFERENCES wc_media(entity_id) DEFERRABLE INITIALLY DEFERRED, media_ref_json TEXT NOT NULL,
 FOREIGN KEY(scene_id,element_local_id) REFERENCES wc_element(scene_id,local_id) DEFERRABLE INITIALLY DEFERRED
);
CREATE TABLE wc_reference (source_id TEXT NOT NULL REFERENCES wc_entity(id), path TEXT NOT NULL, target_id TEXT NOT NULL REFERENCES wc_entity(id), reference_json TEXT NOT NULL, PRIMARY KEY(source_id,path));
CREATE INDEX wc_reference_target ON wc_reference(target_id);
CREATE TABLE wc_revision (id INTEGER PRIMARY KEY, actor TEXT NOT NULL, created_at TEXT NOT NULL, changes_json TEXT NOT NULL);
