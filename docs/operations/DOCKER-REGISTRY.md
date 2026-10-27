# Image registry

How a release image is built and published, and how a partner pulls it.

## Publishing a release

Push a version tag. The release workflow (`.github/workflows/build-and-push.yml`)
builds the `Dockerfile`, pushes the image to the repository host's container
registry under the repository's name, and attaches a build provenance
attestation:

```bash
git tag v1.4.0
git push origin v1.4.0
```

| Tag pushed | Image tags |
|---|---|
| `v1.4.0` | `1.4.0`, `1.4`, `1` |
| A push to the default branch run by hand | `latest` |

The workflow logs in with the job's own token and needs package write
permission; no other secret. It can also be run by hand from the Actions tab.

To build and push by hand to any registry:

```bash
docker login <registry>
docker build -t <registry>/<name>:1.4.0 .
docker push <registry>/<name>:1.4.0
```

## Giving a partner access

Keep the package private and grant the partner's account read access to it
in the registry's package settings. They log in with a token of their own:

```bash
echo "$TOKEN" | docker login <registry> -u <their user> --password-stdin
```

and set, in their `.env`:

```dotenv
IMAGE_NAME=<registry>/<name>
IMAGE_TAG=1.4.0
```

`docker-compose.yml` reads both (`image: ${IMAGE_NAME}:${IMAGE_TAG}`). Pin a
full version in production; `latest` moves. Then follow
[PARTNER-DEPLOYMENT.md](PARTNER-DEPLOYMENT.md).
