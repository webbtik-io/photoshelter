
Copyright 2018 Inovae Sarl.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at :

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

# PhotoShelter Module

This module is meant to integrate photo's stored on PhotoShelter onto your site. It does not copy your photo files to your server, but allows you to access and display them from your Drupal site. Each photo, gallery, and collection on your PhotoShelter site is saved as a type of symlink with meta data as a PS Photo, PS Gallery, or PS Collection. Sites in the "List on Site" category in your PhotoShelter are the only ones that will be copied over.

# Dependencies

- Remote stream wrapper
- Media

# Requirements

- PHP 7.0
- Drupal must be able to read and write this module

# TO-DO

- Add unit tests
- Document fields of each content type
