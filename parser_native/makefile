

all: check

check: afeval.o affunctions.o afparser.o aftypes.o afutils.o main.o
	g++ -g -o check -lboost_regex -licudata afeval.o affunctions.o afparser.o aftypes.o afutils.o main.o

.cpp.o:
	g++ -g -c $<
	
clean:
	rm -f *.o check