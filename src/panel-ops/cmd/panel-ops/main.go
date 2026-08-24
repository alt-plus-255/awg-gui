package main

import (
	"log"
	"net/http"

	"github.com/awggui/panel-ops/internal/httpapi"
)

func main() {
	addr := ":8090"
	log.Printf("panel-ops listening on %s", addr)
	if err := http.ListenAndServe(addr, httpapi.NewHandler()); err != nil {
		log.Fatal(err)
	}
}
