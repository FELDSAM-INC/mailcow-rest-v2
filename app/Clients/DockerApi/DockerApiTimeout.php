<?php

namespace App\Clients\DockerApi;

/**
 * A read of mailcow's container service did not answer in time (feature 011 research R2). Reads
 * change nothing, so it is rendered like an unreachable service: `503 temporary-failure`.
 */
class DockerApiTimeout extends DockerApiUnreachable {}
