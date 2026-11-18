<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 *     esm?: bool,                 // Whether jsDelivr's ESM build is used, defaults to true
 * }>
 */
return [
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    '@floating-ui/dom' => ['version' => '1.8.0'],
    '@floating-ui/core' => ['version' => '1.8.0'],
    '@floating-ui/utils' => ['version' => '0.2.11'],
    '@floating-ui/utils/dom' => ['version' => '0.2.11'],
    'stimulus-use' => ['version' => '0.51.3'],
    'hotkeys-js' => ['version' => '3.10.1'],
    'three' => ['version' => '0.170.0'],
    'three/examples/jsm/controls/OrbitControls.js' => ['version' => '0.170.0'],
    'three/examples/jsm/environments/RoomEnvironment.js' => ['version' => '0.170.0'],
    'three/examples/jsm/utils/BufferGeometryUtils.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/UnrealBloomPass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/ShaderPass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/loaders/GLTFLoader.js' => ['version' => '0.170.0'],
    'three/examples/jsm/libs/meshopt_decoder.module.js' => ['version' => '0.170.0'],
    'three/examples/jsm/objects/Sky.js' => ['version' => '0.170.0'],
    'three/examples/jsm/geometries/RoundedBoxGeometry.js' => ['version' => '0.170.0'],
    'three/examples/jsm/lines/Line2.js' => ['version' => '0.170.0'],
    'three/examples/jsm/lines/LineGeometry.js' => ['version' => '0.170.0'],
    'three/examples/jsm/lines/LineMaterial.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/EffectComposer.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/RenderPass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/GTAOPass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/SMAAPass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/OutputPass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/postprocessing/Pass.js' => ['version' => '0.170.0'],
    'three/examples/jsm/loaders/RGBELoader.js' => ['version' => '0.170.0'],
    'three-gpu-pathtracer' => ['version' => '0.0.23'],
    'three-mesh-bvh' => ['version' => '0.8.3'],
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
];
