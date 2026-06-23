variable "CACHE_SCOPE_PREFIX" {
  default = "pronto-pa"
}

variable "PLATFORMS" {
  default = "linux/amd64,linux/arm64"
}

group "default" {
  targets = ["app", "web"]
}

target "app" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "app"
  platforms  = split(",", PLATFORMS)
  cache-from = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-app"]
  cache-to   = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-app,mode=max"]
}

target "web" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "web"
  platforms  = split(",", PLATFORMS)
  cache-from = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-web"]
  cache-to   = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-web,mode=max"]
}
