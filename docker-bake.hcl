variable "CACHE_SCOPE_PREFIX" {
  default = "pronto-pa"
}

group "default" {
  targets = ["app", "web"]
}

target "app" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "app"
  platforms  = ["linux/amd64", "linux/arm64"]
  cache-from = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-app"]
  cache-to   = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-app,mode=max"]
}

target "web" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "web"
  platforms  = ["linux/amd64", "linux/arm64"]
  cache-from = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-web"]
  cache-to   = ["type=gha,scope=${CACHE_SCOPE_PREFIX}-web,mode=max"]
}
