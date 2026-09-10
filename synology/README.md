# AI Drive Synology package

The generated `noarch` SPK targets DSM 7.0 or later and integrates with Web Station. Install these Synology packages first:

- Web Station 3.0.0 or later
- PHP 8.2

Build from a clean committed tree on Windows with Python 3 installed:

```powershell
powershell -ExecutionPolicy Bypass -File .\release\build-release.ps1
```

Then install `dist/AI-Drive-0.3.0-0001-noarch.spk` from Package Center → Manual Install. The portal is available at `http://NAS_ADDRESS/aidrive/`.

Application data is kept under the DSM package variable directory and linked into the Web Station document root, so an SPK upgrade does not replace user files or the database.
