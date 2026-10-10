/* MIT License: see the repository LICENSE. */
#include <errno.h>
#include <limits.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

static volatile sig_atomic_t interrupted;
static void on_signal(int signal_number) { interrupted = signal_number; }
static double now(void) {
    struct timespec value;
    clock_gettime(CLOCK_MONOTONIC, &value);
    return value.tv_sec + value.tv_nsec / 1000000000.0;
}
static void pause_poll(void) {
    struct timespec delay = {0, 20000000};
    nanosleep(&delay, NULL);
}
static int group_exists(pid_t group) {
    if (kill(-group, 0) == 0) return 1;
    return errno != ESRCH;
}
int main(int argc, char **argv) {
    if (argc < 4 || argv[2][0] != '-' || argv[2][1] != '-' || argv[2][2] != '\0') return 64;
    char *end;
    errno = 0;
    long supplied_owner = strtol(argv[1], &end, 10);
    if (errno || *end || supplied_owner < 2 || supplied_owner > INT_MAX) return 64;
    pid_t owner = (pid_t)supplied_owner;
    if (getppid() != owner) return 78;
    struct sigaction action = {0};
    action.sa_handler = on_signal;
    sigemptyset(&action.sa_mask);
    sigaction(SIGINT, &action, NULL);
    sigaction(SIGTERM, &action, NULL);
    sigaction(SIGHUP, &action, NULL);
    int gate[2];
    if (pipe(gate) != 0) return 71;
    if (getppid() != owner) return 78;
    pid_t child = fork();
    if (child < 0) return 71;
    if (child == 0) {
        close(gate[1]);
        char ready;
        if (read(gate[0], &ready, 1) != 1 || ready != 1) _exit(78);
        close(gate[0]);
        if (setpgid(0, 0) != 0) _exit(71);
        execvp(argv[3], argv + 3);
        perror("unable to start command");
        _exit(127);
    }
    close(gate[0]);
    char ready = 1;
    if (getppid() != owner || write(gate[1], &ready, 1) != 1) {
        close(gate[1]); kill(child, SIGKILL); waitpid(child, NULL, 0); return 78;
    }
    close(gate[1]);
    if (setpgid(child, child) != 0 && errno != EACCES && errno != ESRCH) {
        kill(child, SIGKILL);
        waitpid(child, NULL, 0);
        return 71;
    }
    int root_done = 0, result = 1, stopping = 0, warned = 0;
    double root_end = 0, stop_time = 0;
    for (;;) {
        if (!root_done) {
            int status;
            pid_t reaped = waitpid(child, &status, WNOHANG);
            if (reaped == child) {
                root_done = 1;
                if (!stopping) result = WIFEXITED(status) ? WEXITSTATUS(status) : 128 + WTERMSIG(status);
                root_end = now();
            } else if (reaped < 0 && errno != EINTR) {
                result = 71;
                root_done = 1;
                root_end = now();
            }
        }
        if (root_done && !group_exists(child)) break;
        double elapsed = now();
        if (!stopping && (interrupted || getppid() != owner || (root_done && elapsed - root_end >= 2.0))) {
            stopping = 1;
            stop_time = elapsed;
            if (interrupted) result = 128 + interrupted;
            else if (result == 0 || !root_done) result = 78;
            fputs("[清理] 本次命令的子进程未退出，正在停止并保留构建恢复信息。\n", stderr);
            kill(-child, SIGTERM);
        }
        if (stopping && elapsed - stop_time >= 1.0) kill(-child, SIGKILL);
        if (stopping && elapsed - stop_time >= 4.0) {
            fputs("[失败] 本次命令仍有未退出的子进程；请保留构建目录和日志后重试。\n", stderr);
            result = 78;
            break;
        }
        if (root_done && !stopping && !warned) {
            fputs("[清理] 正在等待本次命令的子进程退出。\n", stderr);
            warned = 1;
        }
        pause_poll();
    }
    return result;
}
