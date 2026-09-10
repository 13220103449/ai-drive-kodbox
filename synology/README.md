# AI Drive Synology package

The generated `noarch` SPK targets DSM 7.0 or later and integrates with Web Station. Install these Synology packages first:

- Web Station 3.0.0 or later
- PHP 8.2

Build from a clean committed tree on Windows with Python 3 installed:

```powershell
powershell -ExecutionPolicy Bypass -File .\release\build-release.ps1
```

Then install `dist/AI-Drive-0.3.1-0002-noarch.spk` from Package Center → Manual Install. The package registers a dedicated Web Station portal on port `8091`; open it at `http://NAS_ADDRESS:8091/`.

Port 80 is not required. DSM checks whether port 8091 is available during installation. If an existing service already occupies 8091, free that port before installing the package.

Application data is kept under the DSM package variable directory and linked into the Web Station document root, so an SPK upgrade does not replace user files or the database.
