group "default" {
  targets = ["app", "web"]
}

target "app" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "app"
  platforms  = ["linux/amd64", "linux/arm64"]
}

target "web" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "web"
  platforms  = ["linux/amd64", "linux/arm64"]
}
