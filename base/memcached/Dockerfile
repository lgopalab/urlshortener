
#https://www.digitalocean.com/community/tutorials/docker-explained-how-to-create-docker-containers-running-memcached
FROM ubuntu

ARG DEBIAN_FRONTEND=noninteractive

# Update the default application repository sources list
RUN apt-get update
RUN apt-get install -y memcached

# Default Memcached run command arguments
CMD ["-m", "128"]

# Set the user to run Memcached daemon
USER daemon

# Set the entrypoint to memcached binary
ENTRYPOINT memcached