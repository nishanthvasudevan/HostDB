#!/usr/bin/perl

use strict;
use HostDB::Shared qw(&load_conf &get_conf $logger);
use HostDB;
use HostDB::Session;
use HostDB::ACL;
use CGI::Fast;
use Data::Dumper;
use Log::Log4perl;
use Benchmark ':hireswallclock';
use URI::Escape;
use YAML::Syck;

# Don't do actual config read inside signal handler.
# Make signal handler as quick as possible!
my $got_hup = 0;
$SIG{HUP} = sub { $got_hup = 1 };

while (my $cgi = new CGI::Fast) {
    if ($got_hup) {
        load_conf();
        $got_hup = 0;
    }
    my $client_ip_header = get_conf("server.client_ip_header") || 'REMOTE_ADDR';
    my $st = Benchmark->new;
    my $method = $cgi->request_method();
    my %params = $cgi->Vars;
    my $uri = $ENV{REQUEST_URI};
    my $qs = $ENV{QUERY_STRING};
    if ($method eq 'DELETE' || $method eq 'PUT' || $method eq 'POST') {
        if (get_conf('server.read_only') ~~ ['1', 'on']) {
            print status_for_error("4031: HostDB is in read-only mode");
            next;
        }
        foreach (split /&/, $qs) {
            my ($k, $v) = split /=/;
            $params{$k} = $v if (! exists $params{$k});
        }
    }
    
    foreach my $key (keys %params) {
        $params{$key} = uri_unescape($params{$key});
    }
    
    #print "Content-type: text/html\n\n";
    #print Dumper \%ENV;
    $logger->debug("Method: $method");
    $logger->debug("uri: $uri");
    $logger->debug("query: $qs");
    $uri =~ s/^\/v[0-9]+//;  # remove version number /v1
    $uri =~ s/\?$qs$//;  # remove query string
    $uri =~ s/\/+$//; # remove / at end
    $uri =~ s/^\/+//; # remove / at start
    my $id = uri_unescape($uri);
    $logger->debug("id: $id");
    
    if ($id =~ /^auth\/session/ && $method eq 'GET') {
        my (undef, undef, $session) = split /\//, $id; 
        $session = $cgi->cookie("HostDB") if (! $session);
        my $username;
        eval {
            $username = validate_session($session, $ENV{$client_ip_header});
        };
        if ($@) {
            print status_for_error($@);
        }
        else {
            print $cgi->header("text/html");
            print $username;
            log_response_time("$method $id", $st, Benchmark->new);
        }
        next;
    }
    elsif ($id =~ /^auth\/session/ && $method eq 'POST') {
        if (exists $params{username} && exists $params{password}) {
            my $session;
            eval {
                validate_credentials($params{username}, $params{password}, $ENV{$client_ip_header});
                $session = generate_session($params{username}, $ENV{$client_ip_header});
            };
            delete $params{password};
            if ($@) {
                print status_for_error($@);
            }
            else {
                my $cookie = $cgi->cookie(  -name => 'HostDB',
                                            -value => $session,
                                            -domain => get_conf('users.human.cookie_domain'),
                                            -expires => '+1d',
                                            -secure => 1,
                                         );
                print $cgi->header(-cookie=>$cookie);
                #print $cgi->header("text/html");
                print "$session";
                log_response_time("$method $id", $st, Benchmark->new);
            }
        }
        else {
            print status_for_error("4001: Missing username or password");
        }
        next;
    }
    elsif ($id =~ /^auth\/can_modify/ && $method eq 'GET') {
        if (get_conf('server.read_only') ~~ ['1', 'on']) {
            print status_for_error("4031: HostDB is in read-only mode");
            next;
        }
        if (exists $params{user} && exists $params{id}) {
            my $response;
            eval {
                $response = can_modify($params{id}, $params{user});
            };
            if ($@) {
                print status_for_error($@);
            }
            else {
                print $cgi->header("text/html");
                print $response;
            }
        }
        else {
            print status_for_error("4001: Missing username or resource ID");
        }
        next;
    }
    if ($method ~~ ['POST', 'PUT', 'DELETE']) {   # http://perldoc.perl.org/perlop.html#Smartmatch-Operator
        if (!exists $params{session}) {
            $params{session} = $cgi->cookie("HostDB");
        }
        eval {
            $params{user} = validate_session($params{session}, $ENV{$client_ip_header});
            $logger->debug("USERNAME = " . $params{user});
        };
        if ($@) {
            print status_for_error($@);
            next;
        }
        if (!defined $params{log} || $params{log} =~ /^\s*$/ ) {
            print status_for_error("4001: Missing parameter: log");
            next;
        }
    }
    
    $logger->debug( "Parameters dump:\n", sub { Dumper(\%params) } );
    my ($response, $mtime, $mtime_header) = ('', '', 'Mtime');
    if ($method ~~ ['GET', 'HEAD']) {
        eval {
            ($response, $mtime) = HostDB::get($id, \%params);
        };
    }
    elsif ($method eq 'PUT') {
        $params{value} = "" if (!exists $params{value});
	$params{value} =~ s/\R/\n/g;
        eval {
            ($response, $mtime) = HostDB::set($id, $params{value}, \%params);
        };
    }
    elsif ($method eq 'POST') {
        if (exists $params{newname}) {
            eval {
                ($response, $mtime) = HostDB::rename($id, $params{newname}, \%params);
            };
        }
        else {
            print status_for_error("4001: Missing parameter: newname");
            next;
        }
    }
    elsif ($method eq 'DELETE') {
        eval {
            $response = HostDB::delete($id, \%params);
        };
    }
    if ($@) {
        print status_for_error($@);
        next;
    }
    else {
        #$mtime = strftime("%a, %e %b %Y %H:%M:%S GMT", gmtime($mtime));
        #$mtime_header = 'Last Modified'; # Browser will start cahching if we do this
        $logger->debug("mtime: $mtime");
        if ($method eq 'GET') {
            print "$mtime_header: $mtime\n";
            print "Content-type: text/html\n\n";
            print $response;
        }
        elsif ($method eq 'HEAD') {
            print "$mtime_header: $mtime\n";
            print "Status: 200 OK\n\n";
        }
        elsif ($method ~~ ['POST', 'PUT']) {
            print "$mtime_header: $mtime\n";
            print "Status: 201 Object created\n\n";
        }
        elsif ($method eq 'DELETE') {
            print "Status: 200 Object deleted\n\n";
        }
        log_response_time("$method $id", $st, Benchmark->new);
    }
}

sub status_for_error() {
    my ($msg) = @_;
    chomp $msg;
    my $code = '500';
    my $status = (split "\n", $msg)[0];
    $status =~ s/ at .*$//;
    if ($status =~ /^(\d{3})\d: (.*)/) {
        $code = $1;
        $status = $2;
    }
    #$msg =~ s/[ \t]*\n[ \t]*/  <--  /g;
    $msg =~ s/\n/ \n/g; # HTTP allows newlines in headers if there is at least one space before it
    return "Status: $code $status\nCallTrace: $msg\n\n";
}

sub log_response_time {
    my ($action, $st, $et) = @_;
    $logger->info( "Time taken to $action: ", sub { timestr(timediff($et,$st)) } );
}

exit 0;

